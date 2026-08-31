<?php
// /Business_only3/public_user/includes/leftbar.php
if (!empty($GLOBALS['msb_leftbar_included'])) {
  return;
}
$GLOBALS['msb_leftbar_included'] = true;
require_once __DIR__ . '/session_user.php';
requireUserLogin();
?>

<style>

/* ============================================================
   Leftbar overlay drawers (do NOT render NAV here)
   These drawers sit on top of the existing left sidebar.
   ============================================================ */
#ttLeftbarOverlays{
  position:fixed;
  left:var(--feedRailW, 84px);
  top:0;
  height:100vh;
  width:min(400px, 30vw);
  z-index:990;
  pointer-events:none;
  background:transparent;
  box-sizing:border-box;
  overflow:hidden;
  --tt-panel-bg:var(--msb-palette-bg, #ffffff);
  --tt-panel-bg-alt:var(--msb-palette-surface-2, #f7f8fa);
  --tt-panel-bg-strong:var(--msb-palette-surface, #eef2f6);
  --tt-panel-border:var(--msb-palette-border, rgba(15,23,42,.08));
  --tt-panel-border-strong:var(--msb-palette-border-strong, rgba(15,23,42,.14));
  --tt-text:var(--msb-palette-text, #101828);
  --tt-muted:var(--msb-palette-text-muted, #667085);
  --tt-soft:var(--msb-palette-text-muted, #98a2b3);
  --tt-bubble-bg:transparent;
  --tt-bubble-border:transparent;
  --tt-thread:var(--msb-palette-border-strong, rgba(15,23,42,.18));
  --tt-control-bg:var(--msb-palette-hover-bg, #f2f4f7);
  --tt-control-hover:var(--msb-palette-nav-hover, #e9edf3);
  --tt-control-border:var(--msb-palette-border, rgba(15,23,42,.08));
  --tt-input-bg:var(--msb-palette-input-bg, #1f1f1f);
  --tt-input-border:var(--msb-palette-border-strong, rgba(15,23,42,.08));
  --tt-input-placeholder:var(--msb-palette-placeholder, #98a2b3);
  --tt-accent:var(--msb-palette-action, var(--msb-palette-link, #2563eb));
  --tt-accent-soft:var(--msb-palette-action-soft, var(--msb-palette-nav-active-bg, rgba(37,99,235,.08)));
  --tt-accent-strong:var(--msb-palette-link-hover, #1d4ed8);
  --tt-focus-bg:var(--msb-palette-hover-bg, rgba(37,99,235,.06));
  --tt-focus-border:var(--msb-palette-border-strong, rgba(37,99,235,.16));
  --tt-focus-shadow:none;
  --tt-send-bg:#7c1730;
  --tt-send-bg-hover:#991c3d;
}
@media (max-width: 991.98px){
  #ttLeftbarOverlays{
    left:0;
    width:min(400px, 88vw);
  }
}
@media (min-width: 992px){
  #ttLeftbarOverlays,
  body.public-leftbar-open.feed-insta-ui #ttLeftbarOverlays{
    left:var(--feedRailW, 84px);
  }
  body.public-leftbar-open #ttLeftbarOverlays,
  body.public-leftbar-open.feed-insta-ui #ttLeftbarOverlays{
    z-index:1315;
  }
  body.public-leftbar-open.feed-insta-ui .feed-ig-rail{
    z-index:1310;
  }
  body.public-leftbar-open.feed-insta-ui .feed-left-rail{
    visibility:hidden;
    pointer-events:none;
  }
}
html.dark-auto #ttLeftbarOverlays,
html[data-theme="dark"] #ttLeftbarOverlays{
  --tt-panel-bg:var(--msb-palette-bg, #171d24);
  --tt-panel-bg-alt:var(--msb-palette-surface-2, #1f2630);
  --tt-panel-bg-strong:var(--msb-palette-surface, #232b35);
  --tt-text:var(--msb-palette-text, #f3f6fb);
  --tt-muted:var(--msb-palette-text-muted, #b1bcce);
}
html[data-msb-appearance] #ttLeftbarOverlays,
html.dark-auto[data-msb-appearance] #ttLeftbarOverlays,
html[data-theme="dark"][data-msb-appearance] #ttLeftbarOverlays{
  --tt-panel-bg:var(--msb-palette-bg);
  --tt-panel-bg-alt:var(--msb-palette-surface-2);
  --tt-panel-bg-strong:var(--msb-palette-surface);
  --tt-panel-border:var(--msb-palette-border);
  --tt-panel-border-strong:var(--msb-palette-border-strong);
  --tt-text:var(--msb-palette-text);
  --tt-muted:var(--msb-palette-text-muted);
  --tt-soft:var(--msb-palette-text-muted);
  --tt-thread:var(--msb-palette-border-strong);
  --tt-control-bg:var(--msb-palette-hover-bg);
  --tt-control-hover:var(--msb-palette-nav-hover);
  --tt-control-border:var(--msb-palette-border);
  --tt-input-bg:var(--msb-palette-input-bg, #1f1f1f);
  --tt-input-border:var(--msb-palette-border-strong);
  --tt-input-placeholder:var(--msb-palette-placeholder);
  --tt-accent:var(--msb-palette-action, var(--msb-palette-link));
  --tt-accent-soft:var(--msb-palette-action-soft, var(--msb-palette-nav-active-bg));
  --tt-accent-strong:var(--msb-palette-link-hover);
  --tt-focus-bg:var(--msb-palette-hover-bg);
  --tt-focus-border:var(--msb-palette-border-strong);
}
#ttLeftbarOverlays .tt-comments-wrap{
  position:absolute !important;
  inset:0 !important;
  background:var(--tt-panel-bg);
  z-index:999 !important;
  display:flex !important;
  flex-direction:column !important;
  overflow:hidden !important;
  min-height:0 !important;
  box-shadow:18px 0 48px rgba(0,0,0,.32);
  transform:translateX(-105%);
  opacity:0;
  pointer-events:none;
  transition:transform .18s ease, opacity .18s ease;
}
#ttLeftbarOverlays .tt-comments-wrap.is-open{
  transform:translateX(0);
  opacity:1;
  pointer-events:auto;
  border-left:1px solid var(--tt-panel-border-strong, #d1d5db);
  border-right:1px solid var(--tt-panel-border-strong, #d1d5db);
  box-sizing:border-box;
}
#ttLeftbarOverlays .tt-readmore-wrap{
  position:absolute !important;
  inset:0 !important;
  background:var(--tt-panel-bg);
  z-index:998 !important;
  display:flex !important;
  flex-direction:column !important;
  overflow:hidden !important;
  min-height:0 !important;
  box-shadow:18px 0 48px rgba(0,0,0,.32);
  transform:translateX(-105%);
  opacity:0;
  pointer-events:none;
  transition:transform .18s ease, opacity .18s ease;
}
#ttLeftbarOverlays .tt-readmore-wrap.is-open{
  transform:translateX(0);
  opacity:1;
  pointer-events:auto;
  border-left:1px solid var(--tt-panel-border-strong, #d1d5db);
  border-right:1px solid var(--tt-panel-border-strong, #d1d5db);
  box-sizing:border-box;
}
#ttLeftbarOverlays .tt-notifications-wrap,
#ttLeftbarOverlays .tt-messages-wrap,
#ttLeftbarOverlays .tt-friend-requests-wrap,
#ttLeftbarOverlays .tt-profile-wrap,
#ttLeftbarOverlays .tt-menu-wrap,
#ttLeftbarOverlays .tt-live-wrap{
  position:absolute !important;
  inset:0 !important;
  pointer-events:none;
  display:flex !important;
  flex-direction:column !important;
  overflow:hidden !important;
  min-height:0 !important;
}
#ttLeftbarOverlays .tt-menu-wrap.is-open,
#ttLeftbarOverlays .tt-profile-wrap.is-open,
#ttLeftbarOverlays .tt-messages-wrap.is-open,
#ttLeftbarOverlays .tt-notifications-wrap.is-open,
#ttLeftbarOverlays .tt-friend-requests-wrap.is-open,
#ttLeftbarOverlays .tt-live-wrap.is-open{
  pointer-events:auto;
  border-left:1px solid var(--tt-panel-border-strong, #d1d5db);
  border-right:1px solid var(--tt-panel-border-strong, #d1d5db);
  box-sizing:border-box;
}

/* ============================================================
   ONE Shamcey sidebar container
   Nav = normal content
   Comments = overlay drawer (no layout conflict)
   ============================================================ */
/* (scoped) Leftbar overlays only */
/* ============================================================
   COMMENTS DRAWER OVERLAY (the key fix)
   ============================================================ */
.tt-comments-wrap{
  position:absolute !important;
  inset:0 !important;
  background:var(--tt-panel-bg);
  z-index:999 !important;
  display:flex !important;
  flex-direction:column !important;
  overflow:hidden !important;
  min-height:0 !important;
  box-shadow:18px 0 48px rgba(0,0,0,.32);
  transform:translateX(-105%);
  opacity:0;
  pointer-events:none;
  transition:transform .18s ease, opacity .18s ease;
  --tt-comments-gutter:16px;
}
.tt-comments-wrap.is-open{
  transform:translateX(0);
  opacity:1;
  pointer-events:auto;
}

/* Header sticky — same left gutter as list + compose */
.tt-comments-head{
  flex: 0 0 auto !important;
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:18px var(--tt-comments-gutter) 14px;
  border-bottom:1px solid transparent;
  background:var(--tt-panel-bg);
  position: sticky !important;
  top: 0 !important;
  z-index: 30 !important;
  box-sizing:border-box;
}

/* Only list scrolls */
.tt-comments-list{
  flex: 1 1 auto !important;
  min-height:0 !important;
  overflow-y:auto !important;
  overflow-x:hidden !important;
  padding:8px var(--tt-comments-gutter) 16px;
  -webkit-overflow-scrolling: touch;
  overscroll-behavior: contain;
  margin-bottom: 0px;
  background:var(--tt-panel-bg);
  scrollbar-width:thin;
  scrollbar-color:rgba(15,23,42,.35) transparent;
  box-sizing:border-box;
}
.tt-comments-list::-webkit-scrollbar{width:2px !important;height:2px !important;}
.tt-comments-list::-webkit-scrollbar-thumb{background:rgba(15,23,42,.35) !important;border-radius:999px;border:0 !important;}
.tt-comments-list::-webkit-scrollbar-track{background:transparent !important;}
.tt-comments-list .text-muted,
.tt-comments-list .tt-comments-empty{
  color:var(--tt-muted) !important;
  margin:0 !important;
  padding:10px 0 !important;
  text-align:left !important;
  text-indent:0 !important;
}

/* Footer sticky — same left gutter as title / empty / comments */
.tt-comments-foot{
  flex: 0 0 auto !important;
  border-top:1px solid rgba(255,255,255,.06);
  padding:10px var(--tt-comments-gutter) 18px;
  background:var(--tt-panel-bg);
  position: sticky !important;
  /* bottom: 155px !important; */
  z-index: 30 !important;
  transform: translateZ(0);
  box-sizing:border-box;
}

/* UI bits */
.tt-comments-head .title{ font-weight:800; font-size:20px; line-height:1.1; color:var(--tt-text); }
.tt-comments-head .count{ font-weight:700; font-size:14px; color:var(--tt-muted); margin-left:8px; }

.tt-close{
  width:28px !important;
  height:28px !important;
  min-width:28px !important;
  min-height:28px !important;
  border-radius:999px;
  border:1px solid transparent;
  background:var(--tt-control-bg);
  color:var(--tt-text);
  display:inline-flex !important;
  align-items:center;
  justify-content:center;
  cursor:pointer;
  padding:0 !important;
  line-height:1 !important;
  flex:0 0 28px !important;
  box-sizing:border-box;
  box-shadow:none;
}
.tt-close i,
.tt-close .icon{
  font-size:14px !important;
  line-height:1 !important;
  width:auto !important;
  height:auto !important;
}
.tt-close:hover{ background:var(--tt-control-hover); }

/* All leftbar doors: same compact close as notifications / profile */
#ttLeftbarOverlays .tt-close,
.msb-profile-door-host .tt-close,
.msb-messages-door-host .tt-close,
.msb-notifications-door-host .tt-close,
.msb-friend-requests-door-host .tt-close{
  width:28px !important;
  height:28px !important;
  min-width:28px !important;
  min-height:28px !important;
  flex:0 0 28px !important;
  padding:0 !important;
}
#ttLeftbarOverlays .tt-close i,
#ttLeftbarOverlays .tt-close .icon,
.msb-profile-door-host .tt-close i,
.msb-messages-door-host .tt-close i,
.msb-notifications-door-host .tt-close i,
.msb-friend-requests-door-host .tt-close i{
  font-size:14px !important;
  line-height:1 !important;
}

/* Comment rows */
.tt-node{position:relative;--tt-avatar-size:20px;}
.tt-node.has-children::after{
  content:"";
  position:absolute;
  left:calc(var(--tt-avatar-size) / 2);
  top:calc(var(--tt-avatar-size) + 10px);
  bottom:20px;
  width:2px;
  background:var(--tt-thread);
  border-radius:999px;
}
.tt-node.has-children.is-collapsed::after{display:none;}
.tt-children{
  margin-left:calc(var(--tt-avatar-size) / 2);
  padding-left:28px;
}
.tt-children.depth-capped{
  margin-left:0;
  padding-left:0;
}
.tt-node.is-reply::before{
  content:"";
  position:absolute;
  left:-30px;
  top:8px;
  width:30px;
  height:17px;
  border-left:2px solid var(--tt-thread);
  border-bottom:2px solid var(--tt-thread);
  border-bottom-left-radius:18px;
}
.tt-node.is-depth-clamped::before{display:none;}
.tt-comment{ display:flex; gap:7px; padding:14px 0 12px; border-radius:18px; box-sizing:border-box; }
.tt-comment.is-alert-focus{ background:var(--tt-focus-bg); border:1px solid var(--tt-focus-border); box-shadow:var(--tt-focus-shadow); margin:2px 0 10px; }
.tt-avatar{
  width:20px;height:20px;border-radius:999px;
  background:#111;color:#fff;
  display:flex;align-items:center;justify-content:center;
  font-weight:700;font-size:8px; flex:0 0 auto;
  overflow:hidden;
  position:relative;
}
.tt-avatar img{ width:100%; height:100%; object-fit:cover; display:block; }
.tt-body{ flex:1; min-width:0; display:flex; flex-direction:column; }
.tt-bubble{display:block;max-width:100%;background:var(--tt-bubble-bg);border:1px solid var(--tt-bubble-border);border-radius:0;padding:0;min-width:0;}
.tt-name{ font-weight:700; font-size:15px; line-height:1.25; color:var(--tt-text); margin-bottom:6px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.tt-text{ font-size:14px; color:var(--tt-text); line-height:1.4; word-wrap:break-word; }
.tt-meta{ display:flex; gap:14px; align-items:center; flex-wrap:wrap; margin-top:8px; font-size:12px; color:var(--tt-muted); }
.tt-meta > span:first-child{ min-width:auto; }
.tt-inlinebtn{border:0;background:transparent;padding:0;color:inherit;font:inherit;font-weight:700;cursor:pointer;}
.tt-inlinebtn:hover{color:var(--tt-text);}
.tt-likebtn{margin-left:auto;order:10;font-weight:500;}
.tt-likebtn i{font-size:15px;margin-right:5px;vertical-align:-1px;}
.tt-toggle-replies{color:var(--tt-muted);font-weight:700;position:relative;padding-left:28px !important;display:inline-flex;align-items:center;gap:5px;}
.tt-toggle-replies::before{
  content:"";
  position:absolute;
  left:0;
  top:50%;
  width:18px;
  height:1px;
  background:var(--tt-thread);
  transform:translateY(-50%);
}
.tt-toggle-replies i{
  font-size:14px;
  line-height:1;
}
.tt-toggle-replies .tt-toggle-caret{
  font-size:11px;
  transition:transform .15s ease;
}
.tt-toggle-replies.is-open .tt-toggle-caret{ transform:rotate(180deg); }
.tt-toggle-replies:hover{color:var(--tt-text);}
.tt-likebtn.liked{color:var(--tt-text);}
.tt-likepill{display:none;}

/* Footer input */
.tt-input-row{ display:flex; align-items:center; gap:10px; }
.tt-input{
  flex:1; border:1px solid var(--tt-input-border);
  border-radius:999px; padding:12px 14px;
  outline:none; font-size:14px;
  background:var(--tt-input-bg);
  color:var(--tt-text);
  min-height:46px;
}
.tt-input::placeholder{ color:var(--tt-input-placeholder); font-size:14px; }
.tt-iconbtn{
  width:25px;height:25px;border-radius:999px;
  border:1px solid transparent;
  background:var(--tt-control-bg); color:var(--tt-text);
  display:flex;align-items:center;justify-content:center;
  cursor:pointer;
  font-size:22px;
}
.tt-iconbtn:hover{ background:var(--tt-control-hover); }
.tt-iconbtn i{ font-size:22px; }
#ttAtBtn,
#ttMediaBtn{
  background:transparent;
  color:#22c55e;
  box-shadow:none;
}
#ttMediaBtn i{
  color:#22c55e;
  font-size:22px;
}
#ttMediaBtn.is-open{
  background:var(--tt-accent-soft, rgba(37,99,235,.12));
}
.tt-send{
  width:25px;height:25px;border-radius:999px;border:none;
  background:var(--tt-send-bg);color:#fff;
  display:flex;align-items:center;justify-content:center;
  cursor:pointer;
}
.tt-send:hover{ background:var(--tt-send-bg-hover); }
.tt-send i{ font-size:21px; }
#ttEmojiBtn.is-open{
  background:var(--tt-accent-soft, rgba(37,99,235,.12));
  color:var(--tt-accent, #2563eb);
}
#ttCommentEmojiPicker{
  position:fixed;
  z-index:2147482500;
  width:min(292px, calc(100vw - 24px));
  display:flex;
  flex-direction:column;
  border:1px solid rgba(255,255,255,.14);
  border-radius:14px;
  background:rgba(48,48,52,.97);
  backdrop-filter:saturate(160%) blur(16px);
  -webkit-backdrop-filter:saturate(160%) blur(16px);
  box-shadow:0 16px 36px rgba(0,0,0,.42);
  color:#f5f5f7;
  overflow:hidden;
}
#ttCommentEmojiPicker[hidden]{ display:none !important; }
.tt-emoji-search-wrap{
  position:relative;
  margin:8px 10px 4px;
}
.tt-emoji-search-wrap i{
  position:absolute;left:10px;top:50%;transform:translateY(-50%);
  color:rgba(255,255,255,.42);font-size:12px;pointer-events:none;
}
.tt-emoji-search{
  width:100%;height:28px;border:0;border-radius:8px;
  background:rgba(0,0,0,.28);color:#f5f5f7;font-size:13px;
  padding:0 10px 0 28px;outline:none;
}
.tt-emoji-search::placeholder{ color:rgba(255,255,255,.42); }
.tt-emoji-body{
  max-height:196px;overflow:auto;padding:2px 8px 6px;
}
.tt-emoji-label{
  font-size:11px;font-weight:600;color:rgba(255,255,255,.55);
  padding:4px 4px 6px;
}
.tt-emoji-grid{
  display:grid;grid-template-columns:repeat(6, minmax(0,1fr));gap:2px;
}
.tt-emoji-grid button{
  width:100%;aspect-ratio:1;border:0;border-radius:8px;
  background:transparent;font-size:24px;line-height:1;cursor:pointer;padding:0;
}
.tt-emoji-grid button:hover,
.tt-emoji-grid button:focus{ background:rgba(255,255,255,.12); outline:none; }
.tt-emoji-empty{
  padding:16px 8px;text-align:center;font-size:12px;color:rgba(255,255,255,.5);
}
.tt-emoji-cats{
  display:flex;align-items:center;justify-content:space-between;gap:2px;
  padding:6px 8px 8px;border-top:1px solid rgba(255,255,255,.08);
  background:rgba(0,0,0,.12);
}
.tt-emoji-cat{
  width:26px;height:26px;border:0;border-radius:999px;background:transparent;
  font-size:14px;line-height:1;cursor:pointer;padding:0;
  display:inline-flex;align-items:center;justify-content:center;
  opacity:.72;
}
.tt-emoji-cat.is-active{ background:#0a84ff; opacity:1; }
.tt-emoji-cat:hover:not(.is-active){ background:rgba(255,255,255,.1); opacity:1; }

.tt-replying{
  display:none; align-items:center; justify-content:space-between;
  gap:8px; font-size:13px; color:var(--tt-muted); padding:0 0 10px;
}
.tt-replying .x{ cursor:pointer; color:var(--tt-text); font-weight:800; }
.tt-comment-media-preview{
  display:none;
  align-items:center;
  gap:8px;
  margin:0 0 8px;
  padding:6px 8px;
  border-radius:12px;
  background:var(--tt-control-bg);
}
.tt-comment-media-preview.is-on{ display:flex; }
.tt-comment-media-preview img{
  display:none;
  width:56px;
  height:56px;
  object-fit:contain;
  border-radius:10px;
  background:transparent;
}
.tt-comment-media-preview .tt-comment-media-name{
  flex:1;
  min-width:0;
  font-size:12px;
  color:var(--tt-muted);
  overflow:hidden;
  text-overflow:ellipsis;
  white-space:nowrap;
}
.tt-comment-media-preview .tt-comment-media-x{
  border:0;
  background:transparent;
  color:var(--tt-text);
  font-weight:800;
  cursor:pointer;
  padding:0 4px;
}
.tt-comment-media,
.tt-comment-gif{
  margin:6px 0 0;
  max-width:88px;
}
.tt-comment-media img,
.tt-comment-gif img{
  display:block !important;
  width:88px;
  height:88px;
  object-fit:contain;
  border-radius:10px;
  background:transparent;
}
#ttCommentGifPicker{
  position:fixed;
  z-index:2147482500;
  width:min(292px, calc(100vw - 24px));
  display:flex;
  flex-direction:column;
  border:1px solid rgba(255,255,255,.14);
  border-radius:14px;
  background:rgba(48,48,52,.97);
  backdrop-filter:saturate(160%) blur(16px);
  -webkit-backdrop-filter:saturate(160%) blur(16px);
  box-shadow:0 16px 36px rgba(0,0,0,.42);
  color:#f5f5f7;
  overflow:hidden;
}
#ttCommentGifPicker[hidden]{ display:none !important; }
.tt-gif-search-wrap{
  position:relative;
  margin:8px 10px 4px;
}
.tt-gif-search-wrap i{
  position:absolute;left:10px;top:50%;transform:translateY(-50%);
  color:rgba(255,255,255,.42);font-size:12px;pointer-events:none;
}
.tt-gif-search{
  width:100%;height:28px;border:0;border-radius:8px;
  background:rgba(0,0,0,.28);color:#f5f5f7;font-size:13px;
  padding:0 10px 0 28px;outline:none;
}
.tt-gif-search::placeholder{ color:rgba(255,255,255,.42); }
.tt-gif-grid{
  display:grid;grid-template-columns:repeat(3, minmax(0,1fr));gap:6px;
  max-height:280px;overflow:auto;padding:6px 10px 10px;
}
.tt-gif-card{
  border:0;border-radius:10px;padding:0;background:rgba(255,255,255,.06);
  cursor:pointer;overflow:hidden;min-height:72px;
}
.tt-gif-card img{
  display:block;width:100%;height:72px;object-fit:contain;background:transparent;
}
.tt-gif-empty{
  padding:18px 12px;text-align:center;font-size:12px;color:rgba(255,255,255,.55);
}

@media (min-width:1025px){
  .tt-comments-wrap{ --tt-comments-gutter:16px; }
  .tt-comments-head{ padding:16px var(--tt-comments-gutter) 14px; }
  .tt-comments-list{ padding:8px var(--tt-comments-gutter) 14px; }
  .tt-comments-foot{ padding:10px var(--tt-comments-gutter) 20px; }
  .tt-comment{ padding-left:0; padding-right:0; }
  .tt-name{ font-size:15px; }
  .tt-text{ font-size:14px; }
}

/* ============================================================
   READ MORE DRAWER (different layout from comments)
   ============================================================ */
.tt-readmore-wrap{
  position:absolute !important;
  inset:0 !important;
  background:var(--tt-panel-bg);
  z-index:998 !important;
  display:flex !important;
  flex-direction:column !important;
  overflow:hidden !important;
  min-height:0 !important;
  box-shadow:18px 0 48px rgba(0,0,0,.32);
  transform:translateX(-105%);
  opacity:0;
  pointer-events:none;
  transition:transform .18s ease, opacity .18s ease;
}
.tt-readmore-wrap.is-open{
  transform:translateX(0);
  opacity:1;
  pointer-events:auto;
}
.tt-rm-head{
  flex:0 0 auto !important;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  padding:20px;
  border-bottom:1px solid var(--tt-panel-border);
  /* background:#fff; */
  background:var(--tt-panel-bg);
  position: sticky !important;
  top:0 !important;
  z-index:30 !important;
}
.tt-rm-left{ display:flex; align-items:center; gap:10px; min-width:0; }
.tt-rm-avatar{
  width:34px;height:34px;border-radius:999px;
  display:flex;align-items:center;justify-content:center;
  font-weight:800;font-size:12px;color:#fff;background:#111;
  flex:0 0 auto;
  overflow:hidden;
}
.tt-rm-avatar.has-photo{
  background:transparent;
}
.tt-rm-avatar img{
  width:100%;
  height:100%;
  object-fit:cover;
  display:block;
  border-radius:999px;
}
.tt-rm-txt{ min-width:0; }
.tt-rm-author{ font-weight:800; font-size:14px; color:var(--tt-text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.tt-rm-sub{ font-size:12px; color:var(--tt-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.tt-rm-title{
  flex:0 0 auto;
  padding:16px 20px 0;
  font-weight:800;
  font-size:18px;
  line-height:1.3;
  color:var(--tt-text);
  word-break:break-word;
  background:var(--tt-panel-bg);
}
.tt-rm-title:empty,
.tt-rm-title.is-empty{ display:none; }
.tt-rm-body{
  flex:1 1 auto !important;
  min-height:0 !important;
  overflow-y:auto !important;
  padding:12px 20px 20px;
  font-size:12px;
  line-height:1.5;
  word-break:break-word;
  color:var(--tt-text);
  background:var(--tt-panel-bg);
  text-align:left;
  scrollbar-width:thin;
  scrollbar-color:rgba(15,23,42,.35) transparent;
}
.tt-rm-body::-webkit-scrollbar{width:2px !important;height:2px !important;}
.tt-rm-body::-webkit-scrollbar-thumb{background:rgba(15,23,42,.35) !important;border-radius:999px;border:0 !important;}
.tt-rm-body::-webkit-scrollbar-track{background:transparent !important;}
.tt-rm-body .tt-richtext{display:block;color:inherit;font:inherit;line-height:inherit;text-align:left;}
.tt-rm-body .tt-rich-p{margin:0 0 12px;white-space:normal;word-break:break-word;text-align:left;}
.tt-rm-body .tt-rich-p:last-child{margin-bottom:0;}
.tt-rm-body .tt-rich-list{margin:0 0 12px;padding-left:22px;}
.tt-rm-body .tt-rich-list.is-ordered{list-style:decimal;}
.tt-rm-body .tt-rich-list.is-bullet{list-style:disc;}
.tt-rm-body .tt-rich-li{margin:0 0 6px;}
.tt-rm-body .tt-rich-li:last-child{margin-bottom:0;}
.tt-rich-ellipsis{display:inline;}

</style>

<!-- leftbar overlays only: no nav duplicated -->
<div id="ttLeftbarOverlays">

  <!-- ✅ COMMENTS drawer overlay -->
  <div class="tt-comments-wrap" id="tt-comments-wrap" aria-hidden="true">

    <div class="tt-comments-head">
      <div>
        <span class="title">Comments</span>
        <span class="count" id="ttCommentsCount">0</span>
      </div>
      <button class="tt-close" type="button" id="ttCommentsClose" title="Close">
        <i class="icon ion-close"></i>
      </button>
    </div>

    <div class="tt-comments-list" id="ttCommentsList">
      <div class="text-muted tt-comments-empty">Select a post to load comments.</div>
    </div>

    <div class="tt-comments-foot">
      <div class="tt-replying" id="ttReplyingRow">
        <div id="ttReplyingTo">Replying…</div>
        <div class="x" id="ttCancelReply">Cancel</div>
      </div>

      <div class="tt-comment-media-preview" id="ttCommentMediaPreview">
        <img id="ttCommentMediaThumb" alt="">
        <span class="tt-comment-media-name" id="ttCommentMediaName"></span>
        <button type="button" class="tt-comment-media-x" id="ttCommentMediaClear" aria-label="Remove GIF">&times;</button>
      </div>
      <form id="ttCommentForm" class="m-0" autocomplete="off">
        <input type="hidden" id="ttPostId" value="0">
        <input type="hidden" id="ttParentId" value="0">
        <div class="tt-input-row">
          <input class="tt-input" id="ttCommentText" type="text" placeholder="Add comment..." />
          <button type="button" class="tt-iconbtn" id="ttMediaBtn" title="GIF" aria-label="GIF" aria-expanded="false" aria-controls="ttCommentGifPicker">
            <i class="icon ion-image"></i>
          </button>
          <button type="button" class="tt-iconbtn" id="ttEmojiBtn" title="Emoji" aria-label="Emoji" aria-expanded="false" aria-controls="ttCommentEmojiPicker">
            <i class="icon ion-happy-outline"></i>
          </button>
          <button class="tt-send" type="submit" title="Send">
            <i class="icon ion-arrow-up-a"></i>
          </button>
        </div>
      </form>
      <div class="tt-gif-picker" id="ttCommentGifPicker" hidden role="dialog" aria-label="GIF">
        <div class="tt-gif-search-wrap">
          <i class="icon ion-search" aria-hidden="true"></i>
          <input type="search" class="tt-gif-search" id="ttCommentGifSearch" placeholder="Search GIFs" autocomplete="off" spellcheck="false">
        </div>
        <div class="tt-gif-grid" id="ttCommentGifGrid"></div>
        <div class="tt-gif-empty" id="ttCommentGifEmpty" hidden>No GIFs match</div>
      </div>
      <div class="tt-emoji-picker" id="ttCommentEmojiPicker" hidden role="dialog" aria-label="Emoji">
        <div class="tt-emoji-search-wrap">
          <i class="icon ion-search" aria-hidden="true"></i>
          <input type="search" class="tt-emoji-search" id="ttCommentEmojiSearch" placeholder="Search" autocomplete="off" spellcheck="false">
        </div>
        <div class="tt-emoji-body">
          <div class="tt-emoji-label" id="ttCommentEmojiLabel">Frequently Used</div>
          <div class="tt-emoji-grid" id="ttCommentEmojiGrid"></div>
          <div class="tt-emoji-empty" id="ttCommentEmojiEmpty" hidden>No emoji found</div>
        </div>
        <div class="tt-emoji-cats" id="ttCommentEmojiCats" role="tablist" aria-label="Emoji categories"></div>
      </div>
    </div>

  </div>


  <?php require_once __DIR__ . '/leftbar_door_anim.js.php'; ?>
  <?php include __DIR__ . '/menu_door.php'; ?>
  <?php include __DIR__ . '/profile_door.php'; ?>
  <?php include __DIR__ . '/messages_door.php'; ?>
  <?php include __DIR__ . '/notifications_door.php'; ?>
  <?php include __DIR__ . '/friend_requests_door.php'; ?>
  <?php
    $msbLiveDoorCanStudio = !empty($canLiveStudio ?? null)
      ? (bool)$canLiveStudio
      : (!empty($headerCanLiveStudio ?? null) ? (bool)$headerCanLiveStudio : null);
    include __DIR__ . '/live_door.php';
  ?>

  <!-- ✅ READ MORE drawer overlay -->
  <div class="tt-readmore-wrap" id="tt-readmore-wrap">
    <div class="tt-rm-head">
      <div class="tt-rm-left">
        <div class="tt-rm-avatar" id="ttRmAvatar" aria-hidden="true"></div>
        <div class="tt-rm-txt">
          <div class="tt-rm-author" id="ttRmAuthor"></div>
          <div class="tt-rm-sub" id="ttRmSub"></div>
        </div>
      </div>
      <button class="tt-close" type="button" id="ttRmClose" title="Close">
        <i class="icon ion-close"></i>
      </button>
    </div>
    <div class="tt-rm-title" id="ttRmTitle"></div>
    <div class="tt-rm-body" id="ttRmBody"></div>
  </div>
</div>
<script>
<?php require __DIR__ . '/comment_gifs.js.php'; ?>
(function(){
  const $wrap = document.getElementById('tt-comments-wrap');
  const $list = document.getElementById('ttCommentsList');
  const $count = document.getElementById('ttCommentsCount');
  const $postId = document.getElementById('ttPostId');
  const $parentId = document.getElementById('ttParentId');
  const $text = document.getElementById('ttCommentText');
  const $form = document.getElementById('ttCommentForm');
  const $mediaBtn = document.getElementById('ttMediaBtn');
  const $mediaPreview = document.getElementById('ttCommentMediaPreview');
  const $mediaThumb = document.getElementById('ttCommentMediaThumb');
  const $mediaName = document.getElementById('ttCommentMediaName');
  const $mediaClear = document.getElementById('ttCommentMediaClear');
  let selectedGif = null;
  const $replyRow = document.getElementById('ttReplyingRow');
  const $replyTo = document.getElementById('ttReplyingTo');
  const $cancelReply = document.getElementById('ttCancelReply');
  const $close = document.getElementById('ttCommentsClose');
  let focusCommentId = 0;
  const defaultPlaceholder = 'Add comment...';
  let currentCommentsPostId = 0;
  let currentComments = [];
  let currentByParent = {};
  const collapsedReplyIds = new Set();
  const MAX_REPLY_CURVE_DEPTH = 4;

  if($list){
    $list.addEventListener('click', function(e){
      var btn = e.target && e.target.closest ? e.target.closest('[data-toggle-replies]') : null;
      if(!btn || !$list.contains(btn)) return;
      e.preventDefault();
      e.stopPropagation();
      if(typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
      var cid = Number(btn.getAttribute('data-toggle-replies') || 0);
      if(!cid) return;
      if(collapsedReplyIds.has(cid)) collapsedReplyIds.delete(cid);
      else collapsedReplyIds.add(cid);
      render(currentComments);
    });
  }

  function esc(s){
    return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  }
  function initials(name){
    name = String(name||'').trim();
    if(!name) return '?';
    const parts = name.split(/\s+/).filter(Boolean);
    return ((parts[0]||'?')[0] + ((parts[1]||'')[0]||'')).toUpperCase();
  }
  function fmtShort(dt){
    if(!dt) return '';
    const m = String(dt).match(/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})/);
    if(!m) return String(dt);
    return m[2] + '-' + m[3] + ' ' + m[4] + ':' + m[5];
  }
  function timeAgo(ts){
    const t = Date.parse(ts || '');
    if(!t) return '';
    const sec = Math.floor((Date.now() - t) / 1000);
    if(sec < 60) return sec + 's';
    const m = Math.floor(sec / 60); if(m < 60) return m + 'm';
    const h = Math.floor(m / 60); if(h < 24) return h + 'h';
    const d = Math.floor(h / 24); if(d < 7) return d + 'd';
    const w = Math.floor(d / 7); if(w < 4) return w + 'w';
    const mo = Math.floor(d / 30); if(mo < 12) return mo + 'mo';
    return Math.floor(d / 365) + 'y';
  }
  function avatarUrl(c, size){
    c = c || {};
    size = Number(size || 72);
    const params = [];
    const uid = Number(c.user_id || c.uid || c.id || 0);
    const username = String(c.username || '').trim();
    const friendCode = String(c.friend_code || '').trim();
    const email = String(c.email || '').trim();
    const name = String(c.display_name || c.author_name || c.fullname || username || 'User').trim();
    if(uid > 0) params.push('u=' + encodeURIComponent(String(uid)));
    if(email) params.push('email=' + encodeURIComponent(email));
    if(friendCode) params.push('friend_code=' + encodeURIComponent(friendCode));
    if(username) params.push('username=' + encodeURIComponent(username));
    if(name) params.push('name=' + encodeURIComponent(name));
    params.push('s=' + encodeURIComponent(String(size)));
    return 'avatar.php?' + params.join('&');
  }
  function replyToggleLabel(count, isOpen){
    const noun = count === 1 ? 'reply' : 'replies';
    return isOpen ? 'Hide replies' : ('Show ' + count + ' ' + noun);
  }
  function descendantReplyCount(id){
    const kids = currentByParent[Number(id)] || [];
    let n = 0;
    kids.forEach(function(k){
      n += 1 + descendantReplyCount(k.id);
    });
    return n;
  }
  function replyToggleHtml(count, isOpen, cid){
    const label = isOpen ? '' : (count + ' ' + (count === 1 ? 'reply' : 'replies'));
    return `<button type="button" class="tt-inlinebtn tt-toggle-replies${isOpen ? ' is-open' : ''}" data-toggle-replies="${cid}" aria-expanded="${isOpen ? 'true' : 'false'}" title="${esc(replyToggleLabel(count, isOpen))}">` +
      `<i class="icon ion-chatbubbles" aria-hidden="true"></i>` +
      (label ? `<span>${esc(label)}</span>` : '') +
      `<i class="icon ion-ios-arrow-down tt-toggle-caret" aria-hidden="true"></i>` +
    `</button>`;
  }
  function collapseRepliesByDefault(comments){
    collapsedReplyIds.clear();
    (Array.isArray(comments) ? comments : []).map(normalizeComment).forEach(c=>{
      if(Number(c.parent_id || 0) > 0) collapsedReplyIds.add(Number(c.parent_id));
    });
  }
  function expandAncestorsForComment(comments, commentId){
    commentId = Number(commentId || 0);
    if(!commentId) return;
    var byId = {};
    (Array.isArray(comments) ? comments : []).map(normalizeComment).forEach(function(c){
      byId[Number(c.id || 0)] = c;
    });
    var cur = byId[commentId];
    var guard = 0;
    while(cur && guard++ < 50){
      collapsedReplyIds.delete(Number(cur.id || 0));
      var pid = Number(cur.parent_id || 0);
      if(pid > 0) collapsedReplyIds.delete(pid);
      cur = pid > 0 ? byId[pid] : null;
    }
  }

  function splitCommentGif(c){
    var text = String(c.comment_text || c.body || c.text || '');
    var path = String(c.media_path || '').trim();
    var m = text.match(/\[\[MSB_GIF:(https?:[^\]]+)\]\]/);
    if(m){
      text = text.replace(/\s*\[\[MSB_GIF:(https?:[^\]]+)\]\]\s*/g, '\n').trim();
      if(!path) path = String(m[1] || '');
    }
    return { text: text, path: path };
  }
  function normalizeComment(c){
    const split = splitCommentGif(c || {});
    return {
      id: Number(c.id || c.comment_id || 0),
      user_id: Number(c.user_id || c.uid || 0),
      parent_id: Number(c.parent_id || c.parentId || 0),
      username: c.username || '',
      display_name: c.display_name || c.author_name || c.username || c.fullname || 'User',
      friend_code: c.friend_code || '',
      email: c.email || '',
      comment_text: split.text,
      media_path: split.path,
      media_type: split.path ? (String(c.media_type || '').trim() || 'gif') : String(c.media_type || '').trim(),
      created_at: c.created_at || c.createdAt || '',
      me_liked: Number(c.me_liked || c.meLiked || 0),
      like_count: Number(c.like_count || c.likeCount || 0),
    };
  }

  function setReply(parentId, who, mode){
    mode = String(mode || 'Reply');
    const isCommentMode = mode === 'Comment';
    $parentId.value = String(parentId||0);
    if(parentId>0){
      $replyRow.style.display = 'flex';
      $replyTo.textContent = (isCommentMode ? 'Commenting on ' : 'Replying to ') + (who || 'comment');
      if($text) $text.placeholder = (isCommentMode ? 'Comment on ' : 'Reply to ') + (who || 'comment');
    } else {
      $replyRow.style.display = 'none';
      $replyTo.textContent = '';
      if($text) $text.placeholder = defaultPlaceholder;
    }
  }
  $cancelReply?.addEventListener('click', ()=>setReply(0,''));

  function clearCommentMedia(){
    selectedGif = null;
    if($mediaPreview) $mediaPreview.classList.remove('is-on');
    if($mediaThumb){ $mediaThumb.removeAttribute('src'); $mediaThumb.style.display = 'none'; }
    if($mediaName) $mediaName.textContent = '';
  }
  function previewCommentGif(item){
    if(!item || !item.url){ clearCommentMedia(); return; }
    selectedGif = { url: String(item.url), title: String(item.title || 'GIF') };
    if($mediaThumb){
      $mediaThumb.style.display = 'block';
      $mediaThumb.src = selectedGif.url;
    }
    if($mediaName) $mediaName.textContent = selectedGif.title;
    if($mediaPreview) $mediaPreview.classList.add('is-on');
  }
  $mediaClear?.addEventListener('click', function(e){
    e.preventDefault();
    clearCommentMedia();
  });

  (function commentGifPicker(){
    var gifBtn = document.getElementById('ttMediaBtn');
    var picker = document.getElementById('ttCommentGifPicker');
    var grid = document.getElementById('ttCommentGifGrid');
    var empty = document.getElementById('ttCommentGifEmpty');
    var search = document.getElementById('ttCommentGifSearch');
    var items = Array.isArray(window.MSB_COMMENT_GIFS) ? window.MSB_COMMENT_GIFS : [];
    if(!gifBtn || !picker || !grid) return;
    function placePicker(){
      if(picker.hidden) return;
      var btnRect = gifBtn.getBoundingClientRect();
      var vw = window.innerWidth || 320;
      var vh = window.innerHeight || 480;
      var pad = 8;
      var pw = Math.min(292, vw - pad * 2);
      picker.style.width = pw + 'px';
      picker.style.visibility = 'hidden';
      picker.style.display = 'flex';
      var ph = picker.offsetHeight || 280;
      var left = Math.max(pad, Math.min(vw - pw - pad, Math.round(btnRect.left + btnRect.width / 2 - pw / 2)));
      var top = Math.round(btnRect.top - ph - 8);
      if(top < pad) top = Math.min(vh - ph - pad, Math.round(btnRect.bottom + 8));
      picker.style.left = left + 'px';
      picker.style.top = Math.max(pad, top) + 'px';
      picker.style.visibility = 'visible';
    }
    function renderGrid(){
      var q = String(search && search.value || '').trim().toLowerCase();
      var list = items.filter(function(item){
        var hay = (item.title + ' ' + item.keywords).toLowerCase();
        return q === '' || hay.indexOf(q) !== -1;
      });
      grid.innerHTML = list.map(function(item){
        return '<button type="button" class="tt-gif-card" data-gif-url="'+esc(item.url)+'" data-gif-title="'+esc(item.title)+'">' +
          '<img src="'+esc(item.url)+'" alt="'+esc(item.title)+'">' +
        '</button>';
      }).join('');
      if(empty) empty.hidden = list.length > 0;
    }
    function closePicker(){
      picker.hidden = true;
      picker.style.visibility = '';
      picker.style.display = '';
      gifBtn.classList.remove('is-open');
      gifBtn.setAttribute('aria-expanded', 'false');
    }
    function openPicker(){
      try{ if(typeof window.__ttCloseCommentEmojiPicker === 'function') window.__ttCloseCommentEmojiPicker(); }catch(e){}
      try{ document.body.appendChild(picker); }catch(e){}
      picker.hidden = false;
      gifBtn.classList.add('is-open');
      gifBtn.setAttribute('aria-expanded', 'true');
      renderGrid();
      requestAnimationFrame(function(){
        placePicker();
        requestAnimationFrame(placePicker);
      });
    }
    window.__ttCloseCommentGifPicker = closePicker;
    gifBtn.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      if(picker.hidden) openPicker();
      else closePicker();
    });
    grid.addEventListener('click', function(e){
      var btn = e.target.closest('[data-gif-url]');
      if(!btn) return;
      e.preventDefault();
      previewCommentGif({ url: btn.getAttribute('data-gif-url'), title: btn.getAttribute('data-gif-title') });
      closePicker();
      submitComment();
    });
    if(search){
      search.addEventListener('input', function(){ renderGrid(); placePicker(); });
      search.addEventListener('keydown', function(e){
        if(e.key === 'Escape'){ e.preventDefault(); closePicker(); }
      });
    }
    document.addEventListener('click', function(e){
      if(picker.hidden) return;
      if(picker.contains(e.target) || gifBtn.contains(e.target)) return;
      closePicker();
    });
    document.addEventListener('keydown', function(e){
      if(e.key === 'Escape' && !picker.hidden) closePicker();
    });
    window.addEventListener('resize', function(){
      if(!picker.hidden) placePicker();
    });
  })();

  (function commentEmojiPicker(){
    var emojiBtn = document.getElementById('ttEmojiBtn');
    var picker = document.getElementById('ttCommentEmojiPicker');
    var grid = document.getElementById('ttCommentEmojiGrid');
    var label = document.getElementById('ttCommentEmojiLabel');
    var empty = document.getElementById('ttCommentEmojiEmpty');
    var cats = document.getElementById('ttCommentEmojiCats');
    var search = document.getElementById('ttCommentEmojiSearch');
    if(!emojiBtn || !picker || !grid || !$text) return;
    var activeCat = 'recents';
    var RECENT_KEY = 'msbCommentEmojiRecents';
    var DEFAULT_RECENTS = ["😂","❤️","😍","😒","👌","☺️","😊","😘","😭","😩","💕","😔","😏","😁","😳","👍","✌️","😉","😌","🙈","😎","🎶","👀","😑","😴","😆","😜","😋","👏"];
    var CATEGORIES = [
      { id:'recents', label:'Frequently Used', icon:'🕒', emojis:null },
      { id:'smileys', label:'Smileys & People', icon:'😊', emojis:["😀","😃","😄","😁","😆","😅","😂","🤣","😊","😇","🙂","🙃","😉","😌","😍","🥰","😘","😗","😙","😚","😋","😛","😝","😜","🤪","🤨","🧐","🤓","😎","🥳","😏","😒","😞","😔","😟","😕","🙁","☹️","😣","😖","😫","😩","🥺","😢","😭","😤","😠","😡","🤬","🤯","😳","🥵","🥶","😱","😨","😰","😥","😓","🤗","🤔","🫡","🤭","🤫","🤥","😶","😐","😑","😬","🙄","😯","😦","😧","😮","😲","🥱","😴","🤤","😪","😵","🤐","🥴","🤢","🤮","🤧","😷","🤒","🤕","🤑","🤠","😈","👿","👻","💀","👽","🤖","🎃","👋","🤚","🖐️","✋","🖖","👌","🤌","🤏","✌️","🤞","🤟","🤘","🤙","👈","👉","👆","👇","☝️","👍","👎","✊","👊","🤛","🤜","👏","🙌","👐","🤲","🤝","🙏","💪","👀","👅","👄","💋"] },
      { id:'animals', label:'Animals & Nature', icon:'🐶', emojis:["🐶","🐱","🐭","🐹","🐰","🦊","🐻","🐼","🐨","🐯","🦁","🐮","🐷","🐸","🐵","🙈","🙉","🙊","🐔","🐧","🐦","🐤","🦆","🦅","🦉","🦇","🐺","🐗","🐴","🦄","🐝","🐛","🦋","🐢","🐍","🐙","🐠","🐬","🐳","🦈","🌵","🎄","🌲","🌳","🌴","🍀","🌸","🌹","🌻","🌞","🌝","🌙","⭐","🌟","✨","⚡","🔥","🌈","☀️","☁️","❄️","⛄","💨","💧","☔","🌊"] },
      { id:'food', label:'Food & Drink', icon:'🍎', emojis:["🍎","🍐","🍊","🍋","🍌","🍉","🍇","🍓","🍒","🍑","🥭","🍍","🥝","🍅","🥑","🥦","🌽","🥕","🍞","🧀","🍔","🍟","🍕","🌮","🌯","🥗","🍝","🍜","🍣","🍦","🍩","🍪","🎂","🍰","🍫","🍬","🍭","☕","🍵","🍺","🍻","🥂","🍷","🍸","🍹"] },
      { id:'activity', label:'Activity', icon:'⚽', emojis:["⚽","🏀","🏈","⚾","🎾","🏐","🎱","🏓","⛳","🏹","🥊","🎽","🛹","🎿","🏄","🏊","🚴","🏆","🥇","🎯","🎮","🎲","🧩","🎭","🎨","🎤","🎧","🎸","🎹","🎺","🎻","🥁","🎬"] },
      { id:'travel', label:'Travel & Places', icon:'🚗', emojis:["🚗","🚕","🚌","🚓","🚑","🚒","🚜","🚲","🛵","🏍️","✈️","🚀","🛸","🚁","⛵","🚤","🚢","🗽","🗼","🏰","🎡","🎢","🏠","🏢","🏥","🏦","🏨","⛪","🌁","🌃","🌄","🌅","🌉"] },
      { id:'objects', label:'Objects', icon:'💡', emojis:["⌚","📱","💻","📷","🎥","📺","⏰","💡","🔦","💸","💵","💎","🔧","🔨","💣","🔮","💊","🎁","🎈","🎉","✉️","📦","📝","📚","📌","✂️","🖊️","🔑","🔒"] },
      { id:'symbols', label:'Symbols', icon:'❤️', emojis:["❤️","🧡","💛","💚","💙","💜","🖤","🤍","💔","❣️","💕","💞","💓","💗","💖","💘","💝","💯","💢","❗","❓","⚠️","✅","♻️","🔵","🟢","🟡","🟠","🔴","🟣","⚫","⚪"] },
      { id:'flags', label:'Flags', icon:'🏁', emojis:["🏳️","🏴","🏁","🚩","🏳️‍🌈","🇺🇸","🇨🇦","🇲🇽","🇧🇷","🇬🇧","🇫🇷","🇩🇪","🇮🇹","🇪🇸","🇮🇳","🇨🇳","🇯🇵","🇰🇷","🇦🇺","🇳🇬","🇿🇦"] }
    ];
    var NAMES = {"👍":"thumbs up","👎":"thumbs down","😂":"joy laugh","❤️":"heart love","😍":"heart eyes","😊":"smile","🙏":"pray","🔥":"fire","👏":"clap","😭":"cry","😎":"cool","🥰":"hearts","😮":"wow","🎉":"party","💯":"hundred","✨":"sparkles","👀":"eyes","🙌":"hands","😘":"kiss","😔":"sad","😒":"unamused","👌":"ok","🙈":"see no evil","😴":"sleep","😜":"wink"};

    function loadRecents(){
      try{
        var raw = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
        if(Array.isArray(raw) && raw.length) return raw.filter(function(e){ return typeof e === 'string' && e; }).slice(0, 48);
      }catch(e){}
      return DEFAULT_RECENTS.slice();
    }
    function saveRecent(emoji){
      if(!emoji) return;
      var list = loadRecents().filter(function(e){ return e !== emoji; });
      list.unshift(emoji);
      try{ localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, 48))); }catch(e){}
    }
    function catById(id){
      for(var i = 0; i < CATEGORIES.length; i++) if(CATEGORIES[i].id === id) return CATEGORIES[i];
      return CATEGORIES[0];
    }
    function currentEmojis(){
      var q = ((search && search.value) || '').trim().toLowerCase();
      if(q){
        var list = [];
        CATEGORIES.forEach(function(cat){
          (cat.id === 'recents' ? loadRecents() : (cat.emojis || [])).forEach(function(e){
            if(!e || list.indexOf(e) !== -1) return;
            if((NAMES[e] || '').indexOf(q) !== -1 || e.indexOf(q) !== -1) list.push(e);
          });
        });
        return list;
      }
      var cat = catById(activeCat);
      return cat.id === 'recents' ? loadRecents() : (cat.emojis || []);
    }
    function renderGrid(){
      var list = currentEmojis().filter(Boolean);
      var searching = !!(search && search.value.trim());
      if(label) label.textContent = searching ? 'Search Results' : (catById(activeCat).label || 'Emojis');
      if(!list.length){
        grid.innerHTML = '';
        if(empty) empty.hidden = false;
        return;
      }
      if(empty) empty.hidden = true;
      grid.innerHTML = list.map(function(e){
        return '<button type="button" data-emoji="'+e+'" title="'+(NAMES[e] || 'Emoji')+'" aria-label="Insert emoji">'+e+'</button>';
      }).join('');
    }
    function renderCats(){
      cats.innerHTML = CATEGORIES.map(function(cat){
        return '<button type="button" class="tt-emoji-cat'+(cat.id === activeCat ? ' is-active' : '')+'" data-cat="'+cat.id+'" title="'+cat.label+'" aria-label="'+cat.label+'">'+cat.icon+'</button>';
      }).join('');
    }
    function placePicker(){
      if(picker.hidden) return;
      var btnRect = emojiBtn.getBoundingClientRect();
      var vw = window.innerWidth || 320;
      var vh = window.innerHeight || 480;
      var pad = 8;
      var pw = Math.min(292, vw - pad * 2);
      picker.style.width = pw + 'px';
      picker.style.visibility = 'hidden';
      picker.style.display = 'flex';
      var ph = picker.offsetHeight || 280;
      var left = Math.max(pad, Math.min(vw - pw - pad, Math.round(btnRect.left + btnRect.width / 2 - pw / 2)));
      var top = Math.round(btnRect.top - ph - 8);
      if(top < pad) top = Math.min(vh - ph - pad, Math.round(btnRect.bottom + 8));
      picker.style.left = left + 'px';
      picker.style.top = Math.max(pad, top) + 'px';
      picker.style.visibility = 'visible';
    }
    function closePicker(){
      picker.hidden = true;
      picker.style.visibility = '';
      picker.style.display = '';
      emojiBtn.classList.remove('is-open');
      emojiBtn.setAttribute('aria-expanded', 'false');
    }
    function openPicker(){
      try{ if(typeof window.__ttCloseCommentGifPicker === 'function') window.__ttCloseCommentGifPicker(); }catch(e){}
      try{ document.body.appendChild(picker); }catch(e){}
      picker.hidden = false;
      emojiBtn.classList.add('is-open');
      emojiBtn.setAttribute('aria-expanded', 'true');
      renderCats();
      renderGrid();
      requestAnimationFrame(function(){
        placePicker();
        requestAnimationFrame(placePicker);
      });
    }
    function insertEmoji(emoji){
      if(!emoji || !$text) return;
      var start = typeof $text.selectionStart === 'number' ? $text.selectionStart : ($text.value || '').length;
      var end = typeof $text.selectionEnd === 'number' ? $text.selectionEnd : start;
      var val = String($text.value || '');
      $text.value = val.slice(0, start) + emoji + val.slice(end);
      var pos = start + emoji.length;
      try{ $text.setSelectionRange(pos, pos); }catch(e){}
      $text.focus();
      try{ $text.dispatchEvent(new Event('input', { bubbles:true })); }catch(e2){}
    }

    window.__ttCloseCommentEmojiPicker = closePicker;

    emojiBtn.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      if(picker.hidden) openPicker();
      else closePicker();
    });
    cats.addEventListener('click', function(e){
      var btn = e.target.closest('[data-cat]');
      if(!btn) return;
      e.preventDefault();
      activeCat = btn.getAttribute('data-cat') || 'recents';
      if(search) search.value = '';
      renderCats();
      renderGrid();
      placePicker();
    });
    grid.addEventListener('click', function(e){
      var btn = e.target.closest('button[data-emoji]');
      if(!btn) return;
      e.preventDefault();
      e.stopPropagation();
      var emoji = btn.getAttribute('data-emoji') || '';
      saveRecent(emoji);
      insertEmoji(emoji);
      closePicker();
    });
    if(search){
      search.addEventListener('input', function(){ renderGrid(); placePicker(); });
      search.addEventListener('keydown', function(e){
        if(e.key === 'Escape'){ e.preventDefault(); closePicker(); }
      });
    }
    document.addEventListener('click', function(e){
      if(picker.hidden) return;
      if(picker.contains(e.target) || emojiBtn.contains(e.target)) return;
      closePicker();
    });
    document.addEventListener('keydown', function(e){
      if(e.key === 'Escape' && !picker.hidden) closePicker();
    });
    window.addEventListener('resize', function(){
      if(!picker.hidden) placePicker();
    });
  })();

  $close?.addEventListener('click', ()=>{
    closeCommentsPanel();
  });

  var commentsPanelOpen = false;

  function commentsDoorDomOpen(){
    return !!($wrap && $wrap.classList.contains('is-open'));
  }

  function syncCommentsPanelState(){
    commentsPanelOpen = commentsDoorDomOpen();
  }

  function readMorePanelOpen(){
    var rm = document.getElementById('tt-readmore-wrap');
    return !!(rm && rm.classList.contains('is-open'));
  }

  function closeOtherPanelsForComments(){
    try {
      if(window.TTReadMore && typeof window.TTReadMore.close === 'function') window.TTReadMore.close();
    } catch(e){}
    try {
      if(window.TTMenu && typeof window.TTMenu.close === 'function') window.TTMenu.close();
    } catch(e){}
    try {
      if(window.TTProfile && typeof window.TTProfile.close === 'function') window.TTProfile.close();
    } catch(e){}
    if(window.TTMessages && typeof window.TTMessages.close === 'function') window.TTMessages.close();
    if(window.TTNotifications && typeof window.TTNotifications.close === 'function') window.TTNotifications.close();
    if(window.TTFriendRequests && typeof window.TTFriendRequests.close === 'function') window.TTFriendRequests.close();
    if(window.TTLive && typeof window.TTLive.close === 'function') window.TTLive.close();
    try {
      if(window.TTLiveRight && typeof window.TTLiveRight.close === 'function') window.TTLiveRight.close();
    } catch(e){}
  }

  function closeCommentsPanel(){
    if(!$wrap || !commentsDoorDomOpen()) return;
    commentsPanelOpen = false;
    $wrap.classList.remove('is-open');
    $wrap.setAttribute('aria-hidden', 'true');
    try{ if(typeof window.__ttCloseCommentEmojiPicker === 'function') window.__ttCloseCommentEmojiPicker(); }catch(e){}
    if(!readMorePanelOpen()){
      document.body.classList.remove('public-leftbar-open', 'profile-leftbar-open');
    }
  }

  function openCommentsPanel(){
    if(!$wrap || commentsDoorDomOpen()) return;
    closeOtherPanelsForComments();
    commentsPanelOpen = true;
    $wrap.classList.add('is-open');
    $wrap.setAttribute('aria-hidden', 'false');
    document.body.classList.add('public-leftbar-open');
    if(document.body.classList.contains('profile-page')){
      document.body.classList.add('profile-leftbar-open');
    }
  }

  function openPanel(){
    if(!$wrap) return;
    closeOtherPanelsForComments();
    syncCommentsPanelState();
    if(commentsPanelOpen) return;
    openCommentsPanel();
  }

  function postIdFromCommentTrigger(trigger){
    if(!trigger) return 0;
    var card = trigger.closest('.mf-card, .public-post-card, article.post');
    if(card){
      var fromCard = Number(card.getAttribute('data-id') || card.getAttribute('data-post-id') || 0);
      if(fromCard > 0) return fromCard;
    }
    return Number(trigger.getAttribute('data-post-id') || 0);
  }

  function fetchCommentsForPost(postId){
    postId = Number(postId || 0);
    if(!postId) return Promise.resolve([]);
    var apiUrl = String(window.API_URL || 'feed_api.php');
    var url = apiUrl + (apiUrl.indexOf('?') >= 0 ? '&' : '?') + 'ajax=view&id=' + encodeURIComponent(String(postId));
    return fetch(url, { credentials:'same-origin', cache:'no-store' })
      .then(function(res){ return res.json(); })
      .then(function(res){
        if(res && res.ok && Array.isArray(res.comments)) return res.comments;
        return [];
      })
      .catch(function(){ return []; });
  }

  function openCommentsForPost(postId, comments, opts){
    postId = Number(postId || 0);
    if(!postId) return;
    opts = opts || {};

    if(commentsDoorDomOpen() && Number(currentCommentsPostId) === postId && opts.toggle){
      closeCommentsPanel();
      return;
    }

    var focusId = Number(opts.commentId || opts.focusCommentId || 0);
    if (focusId > 0 && window.TTComments && typeof window.TTComments.setFocusComment === 'function') {
      window.TTComments.setFocusComment(focusId);
    } else if (typeof window.TTComments.clearFocusComment === 'function') {
      window.TTComments.clearFocusComment();
    }

    openCommentsPanel();

    if(Array.isArray(comments)){
      window.TTComments.setPost(postId, comments, false);
      return;
    }

    window.TTComments.setPost(postId, [], false);
    if($list) $list.innerHTML = '<div class="text-muted tt-comments-empty">Loading comments...</div>';

    fetchCommentsForPost(postId).then(function(items){
      if(Number(currentCommentsPostId) !== postId) return;
      window.TTComments.setPost(postId, items, false);
      if(typeof opts.onLoaded === 'function') opts.onLoaded(items);
    }).catch(function(){
      if(Number(currentCommentsPostId) !== postId) return;
      if($list) $list.innerHTML = '<div class="text-danger tt-comments-empty">Unable to load comments.</div>';
    });
  }

  function reactionLabel(reaction){
    if(window.MSBReactions && typeof window.MSBReactions.label === 'function'){
      return window.MSBReactions.label(reaction || 'love');
    }
    var key = String(reaction || '').trim().toLowerCase();
    if(key === 'like') return 'Like';
    if(key === 'smile') return 'Smile';
    if(key === 'laugh') return 'Laugh';
    if(key === 'wow') return 'Wow';
    if(key === 'sad') return 'Sad';
    if(key === 'angry') return 'Angry';
    return 'Love';
  }

  function commentHtml(c, depth, childrenHtml){
    const dn = c.display_name;
    const liked = (c.me_liked === 1);
    const likeCount = c.like_count;
    const myReaction = String(c.my_reaction || '');
    const currentLabel = reactionLabel(myReaction);
    const when = timeAgo(c.created_at) || fmtShort(c.created_at);
    const avatar = avatarUrl(c, 72);
    const replyCount = (currentByParent[c.id] || []).length;
    const threadCount = descendantReplyCount(c.id) || replyCount;
    const repliesOpen = !collapsedReplyIds.has(c.id);
    const depthClamped = depth > MAX_REPLY_CURVE_DEPTH;
    const childDepthCapped = (depth + 1) > MAX_REPLY_CURVE_DEPTH;
    const replyActionLabel = c._reply_action_label || 'Reply';
    const replyTargetId = Number(c._reply_target_id || c.id);
    const mediaPath = String(c.media_path || '').replace(/"/g, '');
    const textHtml = String(c.comment_text || '').trim()
      ? `<div class="tt-text">${esc(c.comment_text)}</div>`
      : '';
    const mediaHtml = mediaPath
      ? `<div class="tt-comment-gif"><img src="${esc(mediaPath)}" alt="" referrerpolicy="no-referrer"></div>`
      : '';
    return `
      <div class="tt-node${depth > 0 ? ' is-reply' : ''}${replyCount > 0 ? ' has-children' : ''}${replyCount > 0 && !repliesOpen ? ' is-collapsed' : ''}${depthClamped ? ' is-depth-clamped' : ''}" data-cid="${c.id}">
        <div class="tt-comment" data-cid="${c.id}">
          <div class="tt-avatar" title="${esc(dn)}"><img src="${esc(avatar)}" alt="${esc(dn)}"></div>
          <div class="tt-body">
            <div class="tt-bubble">
              <div class="tt-name">${esc(dn)}</div>
              ${textHtml}${mediaHtml}
            </div>
            <div class="tt-meta">
              <span>${esc(when)}</span>
              <button type="button" class="tt-inlinebtn tt-likebtn tt-reactbtn ${liked ? 'liked' : ''}" data-heart="${c.id}" data-reaction="${esc(myReaction)}"><i class="fa fa-heart-o"></i><span data-reaction-label>${esc(liked ? currentLabel : 'Love')}</span></button>
              <button type="button" class="tt-inlinebtn tt-reply-link" data-reply="${replyTargetId}" data-who="${esc(dn)}" data-mode="${esc(replyActionLabel)}">${esc(replyActionLabel)}</button>
              ${replyCount > 0 ? replyToggleHtml(threadCount, repliesOpen, c.id) : ``}
              ${likeCount > 0 ? `<span class="tt-likepill"><i class="icon ion-thumbsup"></i>${likeCount}</span>` : ``}
            </div>
          </div>
        </div>
        ${replyCount > 0 && repliesOpen ? `<div class="tt-children${childDepthCapped ? ' depth-capped' : ''}">${childrenHtml}</div>` : ``}
      </div>
    `;
  }

  function bindHeart(el){
    el.addEventListener('click', async function(e){
      e.preventDefault();
      const cid = Number(this.getAttribute('data-heart')||0);
      const pid = Number($postId.value||0);
      const currentReaction = String(this.getAttribute('data-reaction') || '');
      if(!pid || !cid) return;
      if(currentReaction === 'love') return;

      try{
        const fd = new FormData();
        fd.append('ajax','comment_like');
        fd.append('post_id', String(pid));
        fd.append('comment_id', String(cid));
        fd.append('reaction', 'love');
        const r = await fetch('feed_api.php', { method:'POST', body: fd, cache:'no-store' });
        const data = await r.json();
        if(data && data.ok){
          if(window.TTComments && typeof window.TTComments.refreshCurrent === 'function'){
            window.TTComments.refreshCurrent();
          }
        }
      }catch(err){}
    });
  }

  function render(comments){
    comments = Array.isArray(comments) ? comments.map(normalizeComment) : [];
    currentComments = comments;
    $count.textContent = String(comments.length);

    if(comments.length === 0){
      $list.innerHTML = '<div class="text-muted tt-comments-empty">No comments yet.</div>';
      return;
    }

    const byId = {};
    comments.forEach(c => { byId[c.id] = Object.assign({}, c, { _replies: [] }); });
    const top = [];
    Object.values(byId).forEach(c=>{
      if(c.parent_id > 0 && byId[c.parent_id]){
        byId[c.parent_id]._replies.push(c);
      } else {
        top.push(c);
      }
    });
    function annotateReplyDepth(node, depth, cappedAncestorId){
      const nextCappedAncestorId = (depth === MAX_REPLY_CURVE_DEPTH - 1) ? Number(node.id || 0) : cappedAncestorId;
      node._reply_target_id = (depth >= MAX_REPLY_CURVE_DEPTH && cappedAncestorId > 0) ? cappedAncestorId : Number(node.id || 0);
      node._reply_action_label = (depth >= MAX_REPLY_CURVE_DEPTH) ? 'Comment' : 'Reply';
      node._replies.forEach(child => annotateReplyDepth(child, depth + 1, nextCappedAncestorId));
    }
    top.forEach(node => annotateReplyDepth(node, 0, 0));
    currentByParent = {};
    Object.values(byId).forEach(c => { currentByParent[c.id] = c._replies; });
    function threadHtml(nodes, depth){
      return (nodes || []).map(child => commentHtml(child, depth, threadHtml(child._replies, depth + 1))).join('');
    }

    $list.innerHTML = threadHtml(top, 0);

    function bindReplyLinks(scope){
      (scope || $list).querySelectorAll('[data-reply]').forEach(el=>{
        el.addEventListener('click', function(){
          setReply(
            Number(this.getAttribute('data-reply')||0),
            this.getAttribute('data-who')||'comment',
            this.getAttribute('data-mode')||'Reply'
          );
          $text.focus();
        });
      });
    }

    function focusRenderedComment(){
      if(!focusCommentId) return false;
      $list.querySelectorAll('.tt-comment.is-alert-focus').forEach(node => node.classList.remove('is-alert-focus'));
      const row = $list.querySelector('.tt-comment[data-cid="'+String(focusCommentId)+'"]');
      if(!row) return false;
      row.classList.add('is-alert-focus');
      try{ row.scrollIntoView({ block:'center', behavior:'smooth' }); }catch(err){}
      return true;
    }

    bindReplyLinks($list);
    $list.querySelectorAll('[data-heart]').forEach(bindHeart);
    if(window.MSBReactions){
      $list.querySelectorAll('.tt-reactbtn').forEach(function(btn){
        window.MSBReactions.applyReactionButton(btn, btn.getAttribute('data-reaction') || '', 'love');
      });
    }
    var focused = false;
    if(focusCommentId > 0) focused = focusRenderedComment();
    if(!focused) $list.scrollTop = $list.scrollHeight;
  }

  let commentSending = false;
  async function submitComment(){
    if(commentSending) return;
    const txt = String($text.value||'').trim();
    const gifUrl = selectedGif && selectedGif.url ? String(selectedGif.url) : '';
    const newsItemId = String(window.__newsCommentItemId || '').trim();
    if (newsItemId && txt) {
      try {
        commentSending = true;
        const fd = new FormData();
        fd.append('action', 'comment');
        fd.append('item_id', newsItemId);
        fd.append('text', txt);
        const r = await fetch('news_api.php', { method:'POST', body: fd, cache:'no-store' });
        const data = await r.json();
        if (data && data.ok) {
          $text.value = '';
          setReply(0, '');
          if (window.TTNews && typeof window.TTNews.refreshComments === 'function') {
            window.TTNews.refreshComments();
          }
        }
      } catch (err) {}
      commentSending = false;
      return;
    }
    const news2ItemId = String(window.__news2CommentItemId || '').trim();
    if (news2ItemId && txt) {
      try {
        commentSending = true;
        const fd = new FormData();
        fd.append('action', 'comment');
        fd.append('item_id', news2ItemId);
        fd.append('text', txt);
        const r = await fetch('news2_api.php', { method:'POST', body: fd, cache:'no-store' });
        const data = await r.json();
        if (data && data.ok) {
          $text.value = '';
          setReply(0, '');
          if (window.TTNews2 && typeof window.TTNews2.refreshComments === 'function') {
            window.TTNews2.refreshComments();
          }
        }
      } catch (err) {}
      commentSending = false;
      return;
    }
    const pid = Number($postId.value||0);
    const parent = Number($parentId.value||0);
    if(!pid || (!txt && !gifUrl)) return;

    try{
      commentSending = true;
      const fd = new FormData();
      fd.append('ajax','comment');
      fd.append('post_id', String(pid));
      fd.append('parent_id', String(parent));
      fd.append('comment_text', txt);
      if(gifUrl) fd.append('comment_gif_url', gifUrl);

      const r = await fetch('feed_api.php', { method:'POST', body: fd, credentials:'same-origin', cache:'no-store' });
      const data = await r.json();
        if(data && data.ok){
        $text.value = '';
        clearCommentMedia();
        setReply(0,'');
        if(parent > 0) window.__ttKeepReplyOpenId = parent;
        if(data.comment){
          render((currentComments || []).concat([data.comment]));
        }
        if(window.TTComments && typeof window.TTComments.refreshCurrent === 'function'){
          window.TTComments.refreshCurrent();
        }
      }
    }catch(err){}
    commentSending = false;
  }

  $form?.addEventListener('submit', function(e){
    e.preventDefault();
    submitComment();
  });

  // Public API for feed.php
  window.TTComments = window.TTComments || {};
  window.TTComments.render = render;
  window.TTComments.setFocusComment = function(commentId){
    focusCommentId = Number(commentId || 0);
  };
  window.TTComments.clearFocusComment = function(){
    focusCommentId = 0;
  };
  window.TTComments.focusComment = function(commentId){
    commentId = Number(commentId || focusCommentId || 0);
    if(!commentId || !$list) return false;
    $list.querySelectorAll('.tt-comment.is-alert-focus').forEach(function(node){ node.classList.remove('is-alert-focus'); });
    var row = $list.querySelector('.tt-comment[data-cid="'+String(commentId)+'"]');
    if(!row) return false;
    row.classList.add('is-alert-focus');
    try{ row.scrollIntoView({ block:'center', behavior:'smooth' }); }catch(err){}
    return true;
  };

  window.TTComments.setPost = function(postId, comments, open){
    window.__newsCommentItemId = '';
    window.__news2CommentItemId = '';
    postId = Number(postId || 0);
    const postChanged = postId !== currentCommentsPostId;
    if(postId !== currentCommentsPostId){
      currentCommentsPostId = postId;
    }
    if(focusCommentId <= 0){
      collapseRepliesByDefault(comments);
      var keepId = Number(window.__ttKeepReplyOpenId || 0);
      if(keepId > 0){
        collapsedReplyIds.delete(keepId);
        (Array.isArray(comments) ? comments : []).forEach(function(row){
          var cid = Number(row && row.id || 0);
          var parentId = Number(row && row.parent_id || 0);
          if(cid === keepId){
            while(parentId > 0){
              collapsedReplyIds.delete(parentId);
              var parent = (Array.isArray(comments) ? comments : []).find(function(x){ return Number(x.id || 0) === parentId; });
              parentId = parent ? Number(parent.parent_id || 0) : 0;
            }
          }
        });
      }
      window.__ttKeepReplyOpenId = 0;
    } else {
      collapseRepliesByDefault(comments);
      expandAncestorsForComment(comments, focusCommentId);
    }
    $postId.value = String(postId||0);
    setReply(0,'');
    clearCommentMedia();
    render(comments || []);
    if(open !== false) openPanel();
  };

  window.TTComments.close = function(){ closeCommentsPanel(); };
  window.TTComments.open  = function(){ openCommentsPanel(); };
  window.TTComments.isOpen = function(){
    syncCommentsPanelState();
    return commentsPanelOpen;
  };
  window.TTComments.getPostId = function(){
    return Number(currentCommentsPostId || 0);
  };
  window.TTComments.openForPost = function(postId, comments, opts){
    openCommentsForPost(postId, Array.isArray(comments) ? comments : null, opts || {});
  };
  window.TTComments.toggle = function(postId, comments){
    postId = Number(postId || 0);
    if(commentsDoorDomOpen() && postId > 0 && postId === Number(currentCommentsPostId)){
      closeCommentsPanel();
      return false;
    }
    if(postId > 0){
      openCommentsForPost(postId, Array.isArray(comments) ? comments : null, {});
      return true;
    }
    if(commentsDoorDomOpen()){
      closeCommentsPanel();
      return false;
    }
    openCommentsPanel();
    return true;
  };

  document.addEventListener('click', function(e){
    var trigger = e.target && e.target.closest
      ? e.target.closest('.js-open-comments, .js-open-comments-door, .js-open-profile-comments-door, #profilePostsFeed .mf-comment, .mf-feed .mf-comment, .mf-card .mf-comment, .reel-act[data-act="comment"], #commentCountLink, #commentCountLinkV, #btnViewComments, #btnFooterComment, #btnFooterViewComments, .ig-image-overlay-btn[data-act="comment"]')
      : null;
    if(!trigger) return;
    e.preventDefault();
    e.stopPropagation();
    var postId = postIdFromCommentTrigger(trigger);
    if(typeof window.openFeedCommentsTray === 'function'){
      window.openFeedCommentsTray(postId);
      return;
    }
    if(typeof window.openProfileCommentsTray === 'function'){
      window.openProfileCommentsTray(postId);
      return;
    }
    if(!postId) return;
    openCommentsForPost(postId, null, {});
  }, true);

  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape' && commentsDoorDomOpen()) closeCommentsPanel();
  });

  if(window.MSBReactions){
    window.MSBReactions.bindLikePicker('.tt-reactbtn', async function(btn, reaction){
      const cid = Number(btn.getAttribute('data-heart')||0);
      const pid = Number($postId.value||0);
      if(!pid || !cid || !reaction) return;
      if(String(btn.getAttribute('data-reaction') || '') === String(reaction)) return;
      try{
        const fd = new FormData();
        fd.append('ajax','comment_like');
        fd.append('post_id', String(pid));
        fd.append('comment_id', String(cid));
        fd.append('reaction', String(reaction));
        const r = await fetch('feed_api.php', { method:'POST', body: fd, cache:'no-store' });
        const data = await r.json();
        if(data && data.ok && window.TTComments && typeof window.TTComments.refreshCurrent === 'function'){
          window.TTComments.refreshCurrent();
        }
      }catch(err){}
    });
  }

  // ============================
  // Read More API (LEFTBAR)
  // ============================
  const $rmWrap = document.getElementById('tt-readmore-wrap');
  const $rmClose = document.getElementById('ttRmClose');
  const $rmAvatar = document.getElementById('ttRmAvatar');
  const $rmAuthor = document.getElementById('ttRmAuthor');
  const $rmTitle = document.getElementById('ttRmTitle');
  const $rmSub = document.getElementById('ttRmSub');
  const $rmBody = document.getElementById('ttRmBody');
  var _rmLastKey = '';

  function readMoreDoorDomOpen(){
    return !!($rmWrap && $rmWrap.classList.contains('is-open'));
  }

  function closeOtherPanelsForReadMore(){
    try {
      if(window.TTComments && typeof window.TTComments.close === 'function') window.TTComments.close();
    } catch(e){}
    try {
      if(window.TTMenu && typeof window.TTMenu.close === 'function') window.TTMenu.close();
    } catch(e){}
    if(window.TTProfile && typeof window.TTProfile.close === 'function') window.TTProfile.close();
    if(window.TTMessages && typeof window.TTMessages.close === 'function') window.TTMessages.close();
    if(window.TTNotifications && typeof window.TTNotifications.close === 'function') window.TTNotifications.close();
    if(window.TTFriendRequests && typeof window.TTFriendRequests.close === 'function') window.TTFriendRequests.close();
    if(window.TTLive && typeof window.TTLive.close === 'function') window.TTLive.close();
    try {
      if(window.TTLiveRight && typeof window.TTLiveRight.close === 'function') window.TTLiveRight.close();
    } catch(e){}
  }

  function closeReadMorePanel(){
    if(!$rmWrap || !readMoreDoorDomOpen()) return;
    $rmWrap.classList.remove('is-open');
    $rmWrap.setAttribute('aria-hidden', 'true');
    _rmLastKey = '';
    if(!commentsDoorDomOpen()){
      document.body.classList.remove('public-leftbar-open', 'profile-leftbar-open');
    }
  }

  function openReadMorePanel(){
    if(!$rmWrap || readMoreDoorDomOpen()) return;
    closeOtherPanelsForReadMore();
    $rmWrap.classList.add('is-open');
    $rmWrap.setAttribute('aria-hidden', 'false');
    document.body.classList.add('public-leftbar-open');
    if(document.body.classList.contains('profile-page')){
      document.body.classList.add('profile-leftbar-open');
    }
  }

  $rmClose?.addEventListener('click', function(e){
    e.preventDefault();
    e.stopPropagation();
    closeReadMorePanel();
  });


  function ttRmEsc(s){
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }
  function ttNormalizeReadMorePlain(text){
    let src = String(text == null ? '' : text).replace(/\[\[layout:[a-z0-9_]+\]\]/ig, '');
    src = src.replace(/<\/p>\s*<p[^>]*>/ig, '\n\n');
    src = src.replace(/<br\s*\/?>/ig, '\n');
    src = src.replace(/<[^>]+>/g, '');
    src = src.replace(/\r\n?/g, '\n').replace(/[ \t]+\n/g, '\n').replace(/\n[ \t]+/g, '\n');
    src = src.replace(/\n{3,}/g, '\n\n');
    return src.trim();
  }
  function ttFormatRichText(text){
    const src = ttNormalizeReadMorePlain(text);
    if (!src) return '';
    const lines = src.split('\n');
    const out = [];
    let para = [];
    let listStack = [];
    function escLine(s){ return ttRmEsc(s).replace(/  /g, ' &nbsp;'); }
    function listInfo(line){
      const raw = String(line || '');
      const bullet = raw.match(/^(\s*)([-*•◦▪‣])\s+(.*)$/);
      if (bullet) return { type:'ul', indent: Math.floor((bullet[1] || '').replace(/\t/g, '    ').length / 2), text: bullet[3] || '' };
      const ordered = raw.match(/^(\s*)((?:\d+|[A-Za-z]|[ivxlcdmIVXLCDM]+)[\.)])\s+(.*)$/);
      if (ordered) return { type:'ol', indent: Math.floor((ordered[1] || '').replace(/\t/g, '    ').length / 2), text: ordered[3] || '' };
      return null;
    }
    function flushPara(){ if (!para.length) return; out.push('<p class="tt-rich-p">' + para.map(escLine).join('<br>') + '</p>'); para = []; }
    function closeLists(toLevel){ while (listStack.length > toLevel) { out.push('</li></' + listStack.pop() + '>'); } }
    function openList(type){ out.push('<' + type + ' class="tt-rich-list ' + (type === 'ol' ? 'is-ordered' : 'is-bullet') + '"><li class="tt-rich-li">'); listStack.push(type); }
    lines.forEach(function(line){
      const raw = String(line || '');
      const trimmed = raw.trim();
      const info = listInfo(raw);
      if (!trimmed) { flushPara(); closeLists(0); return; }
      if (info) {
        flushPara();
        const targetLevel = Math.max(0, info.indent + 1);
        while (listStack.length < targetLevel) openList(info.type);
        while (listStack.length > targetLevel) out.push('</li></' + listStack.pop() + '>');
        if (listStack.length && listStack[listStack.length - 1] !== info.type) { out.push('</li></' + listStack.pop() + '>'); openList(info.type); }
        else if (listStack.length) out.push('</li><li class="tt-rich-li">');
        out.push('<span class="tt-rich-line">' + escLine(info.text) + '</span>');
      } else {
        if (listStack.length) closeLists(0);
        para.push(raw);
      }
    });
    flushPara();
    closeLists(0);
    return '<div class="tt-richtext">' + out.join('') + '</div>';
  }
  window.TTRichText = window.TTRichText || { formatHtml: ttFormatRichText, normalizePlain: ttNormalizeReadMorePlain };

  window.TTReadMore = window.TTReadMore || {};

  function readMoreKey(payload){
    payload = payload || {};
    return [
      String(payload.title || ''),
      String(payload.author || ''),
      String(payload.date || ''),
      String(payload.body || '')
    ].join('\u0001');
  }

  window.TTReadMore.open = function(payload){
    payload = payload || {};
    closeOtherPanelsForReadMore();
    openReadMorePanel();

    const author = String(payload.author || '').trim();
    const titleRaw = String(payload.title || '').trim();
    const displayTitle = (titleRaw && titleRaw.toLowerCase() !== 'post') ? titleRaw : '';
    const avatarUrl = String(payload.avatarUrl || payload.avatar_url || '').trim();
    const avatarText = String(payload.avatarText || 'P').slice(0, 2).toUpperCase();
    const avatarBg = payload.avatarBg || '#111';

    if($rmAvatar){
      if(avatarUrl){
        $rmAvatar.innerHTML = '<img src="' + ttRmEsc(avatarUrl) + '" alt="' + ttRmEsc(author || 'Profile') + '">';
        $rmAvatar.style.background = 'transparent';
        $rmAvatar.classList.add('has-photo');
      } else {
        $rmAvatar.innerHTML = '';
        $rmAvatar.textContent = avatarText;
        $rmAvatar.style.background = avatarBg;
        $rmAvatar.classList.remove('has-photo');
      }
    }
    if($rmAuthor){
      if(author){
        $rmAuthor.textContent = author;
        $rmAuthor.style.display = '';
      } else {
        $rmAuthor.textContent = '';
        $rmAuthor.style.display = 'none';
      }
    }
    if($rmTitle){
      if(displayTitle){
        $rmTitle.textContent = displayTitle;
        $rmTitle.classList.remove('is-empty');
        $rmTitle.style.display = '';
      } else {
        $rmTitle.textContent = '';
        $rmTitle.classList.add('is-empty');
        $rmTitle.style.display = 'none';
      }
    }
    if($rmSub){
      const date = String(payload.date || '').trim();
      $rmSub.textContent = date;
      $rmSub.style.display = date ? '' : 'none';
    }
    if($rmBody) $rmBody.innerHTML = ttFormatRichText(String(payload.body || ''));
    _rmLastKey = readMoreKey(payload);
  };
  window.TTReadMore.isOpen = function(){
    return readMoreDoorDomOpen();
  };
  window.TTReadMore.toggle = function(payload){
    payload = payload || {};
    var key = readMoreKey(payload);
    if(readMoreDoorDomOpen() && key && key === _rmLastKey){
      closeReadMorePanel();
      return false;
    }
    window.TTReadMore.open(payload);
    return true;
  };
  window.TTReadMore.close = function(){
    closeReadMorePanel();
  };

  function readMorePayloadFromTrigger(trigger){
    if(!trigger) return null;
    var card = trigger.closest('.mf-card, .public-post-card, article.post, .ig-card');

    // Prefer attributes on the Read more link (public.php / news set these).
    var body = String(
      trigger.getAttribute('data-body')
      || trigger.getAttribute('data-full')
      || ''
    ).trim();

    var bodyHost = trigger.closest('.mf-body, .mf-reel-body, .mf-video-body, .standard-text-caption, .reel-caption, .post-copy');
    if(!body && bodyHost){
      body = String(bodyHost.getAttribute('data-full') || '').trim();
    }
    if(!body && card){
      body = String(card.getAttribute('data-full-desc') || card.getAttribute('data-body') || '').trim();
    }
    if(!body) return null;

    var title = String(
      trigger.getAttribute('data-title')
      || (card && card.getAttribute('data-title'))
      || 'Post'
    ).trim();
    var author = String(
      trigger.getAttribute('data-author')
      || (card && card.getAttribute('data-author'))
      || ''
    ).trim();
    var date = String(
      trigger.getAttribute('data-date')
      || (card && card.getAttribute('data-date'))
      || ''
    ).trim();
    var avatarText = String(
      trigger.getAttribute('data-avatar')
      || trigger.getAttribute('data-avatar-text')
      || (card && card.getAttribute('data-avatar-text'))
      || 'P'
    ).trim() || 'P';
    var avatarUrl = String(
      trigger.getAttribute('data-avatar-url')
      || (card && card.getAttribute('data-avatar-url'))
      || ''
    ).trim();

    return {
      title: title || 'Post',
      author: author,
      date: date,
      avatarText: avatarText.slice(0, 2).toUpperCase(),
      avatarBg: '#111827',
      avatarUrl: avatarUrl,
      body: body
    };
  }

  document.addEventListener('click', function(e){
    var trigger = e.target && e.target.closest
      ? e.target.closest('.js-open-readmore-door, .js-open-readmore, .mf-readmore, #pvCapReadMore, .ig-cap-readmore, #pvFooterReadMore, #pvInlineReadMore, #btnReadMore, #postList .pl-readmore')
      : null;
    if(!trigger) return;
    e.preventDefault();
    e.stopPropagation();
    if(typeof window.openFeedReadMoreTray === 'function'){
      window.openFeedReadMoreTray(trigger);
      return;
    }
    if(typeof window.openProfileReadMoreTray === 'function'){
      window.openProfileReadMoreTray(trigger);
      return;
    }
    var payload = readMorePayloadFromTrigger(trigger);
    if(payload){
      window.TTReadMore.toggle(payload);
      document.body.classList.add('public-leftbar-open');
      if(document.body.classList.contains('profile-page')){
        document.body.classList.add('profile-leftbar-open');
      }
    }
  }, true);

  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape' && readMoreDoorDomOpen()) closeReadMorePanel();
  });

  document.addEventListener('click', function(e){
    var target = e.target;
    if(!target || !target.closest) return;
    if(target instanceof Node && !document.contains(target)) return;

    var menuWrap = document.getElementById('tt-menu-wrap');
    var commentsWrap = document.getElementById('tt-comments-wrap');
    var readWrap = document.getElementById('tt-readmore-wrap');
    var profileWrap = document.getElementById('tt-profile-wrap');
    var messagesWrap = document.getElementById('tt-messages-wrap');
    var notificationsWrap = document.getElementById('tt-notifications-wrap');
    var friendRequestsWrap = document.getElementById('tt-friend-requests-wrap');
    var liveWrap = document.getElementById('tt-live-wrap');
    var menuOpen = !!(menuWrap && menuWrap.classList.contains('is-open'));
    var commentsOpen = !!(commentsWrap && commentsWrap.classList.contains('is-open'));
    var readOpen = !!(readWrap && readWrap.classList.contains('is-open'));
    var profileOpen = !!(profileWrap && profileWrap.classList.contains('is-open'));
    var messagesOpen = !!(messagesWrap && messagesWrap.classList.contains('is-open'));
    var notificationsOpen = !!(notificationsWrap && notificationsWrap.classList.contains('is-open'));
    var friendRequestsOpen = !!(friendRequestsWrap && friendRequestsWrap.classList.contains('is-open'));
    var liveOpen = !!(liveWrap && liveWrap.classList.contains('is-open'));
    if(!menuOpen && !commentsOpen && !readOpen && !profileOpen && !messagesOpen && !notificationsOpen && !friendRequestsOpen && !liveOpen) return;

    if(target.closest('#tt-menu-wrap, #tt-comments-wrap, #tt-readmore-wrap, #tt-profile-wrap, #tt-messages-wrap, #tt-notifications-wrap, #tt-friend-requests-wrap, #tt-live-wrap, #ttCommentEmojiPicker, #ttCommentGifPicker, #ttEmojiBtn, #ttMediaBtn, #ttMenuClose, #ttCommentsClose, #ttRmClose, #ttProfileClose, #ttMessagesClose, #ttNotificationsClose, #ttFriendRequestsClose')) return;
    if(target.closest('.js-open-menu-door, .ig-story-item, .js-open-comments, .js-open-comments-door, .js-open-readmore, .js-open-readmore-door, .js-open-profile-door, .js-open-messages-door, .js-open-notifications-door, .js-open-friend-requests-door, .js-open-live-door, .js-open-live-studio-browse, .js-open-live-software-browse, .js-open-order-details-door, .js-open-shop-buy-door, .feed-ig-avatar')) return;
    if(target.closest('#tt-stories-wrap, #tt-live-right-wrap, #ttStoriesClose')) return;
    if(target.closest('.mf-comment, .js-open-profile-comments-door, .mf-readmore, #commentCountLink, #commentCountLinkV, #btnViewComments, #btnFooterComment, #btnFooterViewComments, .ig-image-overlay-btn[data-act="comment"], #pvCapReadMore, .ig-cap-readmore, #pvFooterReadMore, #pvInlineReadMore, #btnReadMore, #btnOpenCommentsDrawer, #postList .pl-readmore')) return;

    if(menuOpen && window.TTMenu && typeof window.TTMenu.close === 'function') window.TTMenu.close();
    if(commentsOpen){
      if(window.TTComments && typeof window.TTComments.close === 'function') window.TTComments.close();
      else if(commentsWrap) commentsWrap.classList.remove('is-open');
    }
    if(readOpen){
      if(window.TTReadMore && typeof window.TTReadMore.close === 'function') window.TTReadMore.close();
      else if(readWrap) readWrap.classList.remove('is-open');
    }
    if(profileOpen){
      if(window.TTProfile && typeof window.TTProfile.close === 'function') window.TTProfile.close();
      else if(profileWrap) profileWrap.classList.remove('is-open');
    }
    if(messagesOpen){
      if(window.TTMessages && typeof window.TTMessages.close === 'function') window.TTMessages.close();
      else if(messagesWrap) messagesWrap.classList.remove('is-open');
    }
    if(notificationsOpen){
      if(window.TTNotifications && typeof window.TTNotifications.close === 'function') window.TTNotifications.close();
      else if(notificationsWrap) notificationsWrap.classList.remove('is-open');
    }
    if(friendRequestsOpen){
      if(window.TTFriendRequests && typeof window.TTFriendRequests.close === 'function') window.TTFriendRequests.close();
      else if(friendRequestsWrap) friendRequestsWrap.classList.remove('is-open');
    }
    if(liveOpen){
      if(window.TTLive && typeof window.TTLive.close === 'function') window.TTLive.close();
      else if(liveWrap) liveWrap.classList.remove('is-open');
    }
  });

})();
</script>