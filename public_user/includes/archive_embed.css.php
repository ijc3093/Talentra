<?php
declare(strict_types=1);
?>
<style id="gear-archive-embed-css">
body.profile-page.profile-gear-mode .gear-shell.is-archive-open .gear-row-pane,
body.profile-page.profile-gear-mode .gear-shell.is-archive-open .gear-main,
body.profile-page.profile-gear-mode .gear-shell.is-favorites-open .gear-row-pane,
body.profile-page.profile-gear-mode .gear-shell.is-favorites-open .gear-main{
  display:none !important;
}
.ig-archive-embed-host{
  flex:1 1 auto;
  min-width:0;
  min-height:0;
  overflow:hidden;
  --ig-bg:var(--msb-palette-bg, #fff);
  --ig-text:var(--msb-palette-text, #0f0f0f);
  --ig-muted:var(--msb-palette-text-muted, #8e8e8e);
  --ig-line:var(--msb-palette-border, #dbdbdb);
  --ig-badge:#fff;
  --ig-badge-text:#0f0f0f;
  --ig-tile:#1a1a1a;
  --ig-sheet:var(--msb-palette-bg, #fff);
  --ig-danger:#ed4956;
  --msb-top-story-item:36px;
  --msb-top-story-ring:32px;
}
html[data-theme="dark"] .ig-archive-embed-host,
html.dark-auto .ig-archive-embed-host{
  --ig-bg:var(--msb-palette-bg, #000);
  --ig-text:var(--msb-palette-text, #f5f5f5);
  --ig-muted:var(--msb-palette-text-muted, #a8a8a8);
  --ig-line:var(--msb-palette-border, #262626);
  --ig-tile:#121212;
  --ig-sheet:var(--msb-palette-bg, #1a1a1a);
}
#gearArchiveEmbed,
#gearFavoritesEmbed{
  display:none;
}
.gear-shell.is-favorites-open #gearFavoritesEmbed{
  display:flex;
  flex-direction:column;
  flex:1 1 auto;
  min-width:0;
  min-height:0;
  overflow-x:hidden;
  overflow-y:auto;
  -webkit-overflow-scrolling:touch;
  background:var(--msb-palette-bg, var(--ig-bg, #fff));
  color:var(--msb-palette-text, var(--ig-text, #0f0f0f));
}
.gear-shell.is-archive-open #gearArchiveEmbed,
.gear-shell.is-favorites-open #gearFavoritesEmbed{
  display:flex;
  flex-direction:column;
}
.ig-archive-embed-host .ig-archive{
  max-width:none;
  width:100%;
  margin:0;
  height:100%;
  min-height:0;
  flex:1 1 auto;
  display:flex;
  flex-direction:column;
  overflow:hidden;
  background:var(--ig-bg);
  color:var(--ig-text);
}
.ig-archive-embed-host .ig-archive-top{flex:0 0 auto !important;background:var(--ig-bg);padding:2px 0 0}
.ig-archive-embed-host .ig-archive-head{display:flex;align-items:center;gap:8px;min-height:0;padding:0 16px 4px}
.ig-archive-embed-host .ig-archive-title{font-size:20px;font-weight:700;letter-spacing:-.02em;line-height:1.1;margin:0;color:var(--ig-text)}
.ig-archive-embed-host .ig-archive-stories-block{padding:0}
.ig-archive-embed-host .ig-archive-stories-label{
  margin:0 16px 4px;font-size:12px;font-weight:800;letter-spacing:.04em;
  text-transform:uppercase;color:var(--ig-muted);
}
.ig-archive-embed-host .ig-stories-wrap{position:relative;padding:2px 0 4px}
.ig-archive-embed-host .ig-archive-note{margin:4px 16px 0;font-size:11px;font-weight:500;line-height:1.3;color:var(--ig-muted)}
.ig-archive-embed-host .ig-archive-note--stories{margin:2px 16px 0;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.ig-archive-embed-host .ig-archive-body{
  flex:1 1 auto;min-height:0;display:flex;flex-direction:column;overflow:hidden;
  margin-top:8px;padding-top:8px;border-top:1px solid var(--ig-line);
}
.ig-archive-embed-host .ig-archive-section{flex:1 1 auto;min-height:0;display:flex;flex-direction:column}
.ig-archive-embed-host .ig-archive-posts-meta .ig-archive-note{margin-top:0 !important;margin-bottom:6px !important}
.ig-archive-embed-host .ig-archive-section-title{
  margin:0 16px 6px;font-size:12px;font-weight:800;letter-spacing:.04em;
  text-transform:uppercase;color:var(--ig-muted);
}
.ig-archive-embed-host .ig-archive-grid-scroll{
  flex:1 1 auto;min-height:0;overflow-x:hidden;overflow-y:auto;
  -webkit-overflow-scrolling:touch;overscroll-behavior:contain;padding-bottom:16px;
}
.ig-archive-embed-host .ig-stories-bar{display:flex;align-items:flex-start;padding:0 8px;min-height:0}
.ig-archive-embed-host .ig-stories-track{
  display:flex;align-items:flex-start;gap:10px;overflow-x:auto;overflow-y:hidden;
  flex:0 0 auto !important;min-width:0;min-height:0;scrollbar-width:none;padding:0 6px;
}
.ig-archive-embed-host .ig-stories-track::-webkit-scrollbar{display:none}
.ig-archive-embed-host .ig-stories-track.is-empty{justify-content:flex-start;min-height:0}
.ig-archive-embed-host .ig-story-item{
  flex:0 0 auto;width:var(--msb-top-story-item);min-width:var(--msb-top-story-item);
  text-align:center;cursor:pointer;border:0;padding:0;background:transparent;font:inherit;color:inherit;
  outline:none;box-shadow:none;-webkit-tap-highlight-color:transparent;
}
.ig-archive-embed-host .ig-story-item:focus,
.ig-archive-embed-host .ig-story-item:focus-visible,
.ig-archive-embed-host .ig-story-item:active{
  outline:none;box-shadow:none;
}
.ig-archive-embed-host .ig-story-ring{
  width:var(--msb-top-story-ring) !important;height:var(--msb-top-story-ring) !important;margin:0 auto 4px;padding:2px;
  border-radius:50%;background:linear-gradient(45deg,#f58529,#dd2a7b,#8134af,#515bd4);box-sizing:border-box;
}
.ig-archive-embed-host .ig-story-ring img,
.ig-archive-embed-host .ig-story-ring video,
.ig-archive-embed-host .ig-story-thumb{
  display:block;width:100%;height:100%;border-radius:50%;border:2px solid var(--ig-bg);
  object-fit:cover;background:#efefef;box-sizing:border-box;
}
.ig-archive-embed-host .ig-story-name{
  display:block;max-width:var(--msb-top-story-item);margin:0 auto;font-size:10px;line-height:1.15;
  color:var(--ig-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.ig-archive-embed-host .ig-story-empty{cursor:default;pointer-events:none;width:auto;max-width:118px}
.ig-archive-embed-host .ig-story-ring-empty{background:rgba(127,127,127,.16)!important;background-image:none!important}
.ig-archive-embed-host .ig-story-empty-icon{
  display:flex;align-items:center;justify-content:center;width:100%;height:100%;border-radius:50%;
  border:2px solid var(--ig-bg);background:var(--msb-palette-hover-bg,#f2f4f7);
  color:var(--msb-palette-text-muted,#98a2b3);font-size:16px;line-height:1;box-sizing:border-box;
}
.ig-archive-embed-host .ig-stories-next{
  flex:0 0 auto;width:28px;height:28px;margin-left:4px;border:0;border-radius:999px;
  background:rgba(0,0,0,.08);color:var(--ig-text);display:none;align-items:center;justify-content:center;cursor:pointer;
}
.ig-archive-embed-host .ig-stories-bar:not(.is-empty) .ig-stories-next{display:inline-flex}
.ig-archive-embed-host .ig-stories-next svg{width:12px;height:12px}
.ig-archive-embed-host .ig-archive-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:2px;padding:0 1px 8px}
.ig-archive-embed-host .ig-archive-tile{
  position:relative;aspect-ratio:1/1;background:var(--ig-tile);overflow:hidden;border:0;padding:0;cursor:pointer;color:#fff;
}
.ig-archive-embed-host .ig-archive-tile .react-overlay{
  position:absolute;inset:0;z-index:6;
  background:rgba(2,8,23,.58);
  opacity:0;pointer-events:none;
  transition:opacity .16s ease;
  display:flex;align-items:center;justify-content:center;
  gap:10px;padding:10px;
  color:#fff;
}
.ig-archive-embed-host .ig-archive-tile:hover .react-overlay,
.ig-archive-embed-host .ig-archive-tile:focus-visible .react-overlay{
  opacity:1;
}
.ig-archive-embed-host .ig-archive-tile .react-btn{
  display:flex;align-items:center;gap:7px;
  padding:8px 10px;border-radius:999px;
  background:rgba(255,255,255,.16);color:#fff;
  font-weight:900;font-size:12px;
  border:1px solid rgba(255,255,255,.14);
  pointer-events:none;
}
.ig-archive-embed-host .ig-archive-tile .react-btn i{font-size:16px}
.ig-archive-embed-host .ig-archive-tile .react-btn .n,
.ig-archive-embed-host .ig-archive-tile .react-btn .vnum{font-size:12px;font-weight:900;min-width:10px;text-align:left}
.ig-archive-embed-host .ig-archive-media{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block;background:#1a1a1a}
.ig-archive-embed-host .ig-archive-tile .ig-archive-fallback{
  position:absolute;inset:0;display:flex;align-items:flex-end;padding:12px 10px;
  background:linear-gradient(180deg,#2a2a2a 0%,#111 100%);color:#fff;font-size:12px;font-weight:600;line-height:1.35;
  overflow:hidden;
}
.ig-archive-embed-host .ig-archive-tile .ig-archive-fallback span{
  display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;
  overflow:hidden;word-break:break-word;overflow-wrap:anywhere;text-align:left;
}
.ig-archive-embed-host .ig-archive-date{
  position:absolute;top:8px;left:8px;z-index:2;min-width:42px;padding:5px 7px 4px;border-radius:6px;
  background:var(--ig-badge);color:var(--ig-badge-text);line-height:1.05;pointer-events:none;
}
.ig-archive-embed-host .ig-archive-date-day{display:block;font-size:15px;font-weight:800}
.ig-archive-embed-host .ig-archive-date-month{display:block;font-size:9px;font-weight:700;text-transform:uppercase;opacity:.85}
.ig-archive-embed-host .ig-archive-video-mark{
  position:absolute;top:8px;right:8px;z-index:2;width:22px;height:22px;border-radius:999px;
  background:rgba(0,0,0,.45);display:grid;place-items:center;pointer-events:none;
}
.ig-archive-embed-host .ig-archive-video-mark svg{width:11px;height:11px;fill:#fff}
.ig-archive-embed-host .ig-archive-empty{padding:48px 28px 40px;text-align:center;color:var(--ig-muted)}
.ig-archive-embed-host .ig-archive-empty strong{display:block;color:var(--ig-text);font-size:18px;font-weight:700;margin-bottom:8px}
.ig-archive-embed-host .ig-archive-empty p{margin:0;font-size:13px;font-weight:500;line-height:1.5}
html.ig-archive-modal-open,
html.ig-archive-modal-open body{overflow:hidden !important}
.ig-archive-viewer{
  --ig-sheet:var(--msb-palette-bg, #fff);
  --ig-text:var(--msb-palette-text, #0f0f0f);
  --ig-line:var(--msb-palette-border, #dbdbdb);
  --ig-danger:#ed4956;
  position:fixed;inset:0;z-index:14000;display:none;
  align-items:flex-end;justify-content:center;
  background:rgba(0,0,0,.55);
  padding:16px 12px calc(16px + env(safe-area-inset-bottom, 0px));
}
html[data-theme="dark"] .ig-archive-viewer,
html.dark-auto .ig-archive-viewer{
  --ig-sheet:var(--msb-palette-bg, #1a1a1a);
  --ig-text:var(--msb-palette-text, #f5f5f5);
  --ig-line:var(--msb-palette-border, #262626);
}
.ig-archive-viewer.is-open{display:flex !important}
.ig-archive-sheet{
  display:flex;flex-direction:column;
  width:fit-content;max-width:min(100%,420px);min-width:min(100%,260px);
  background:var(--ig-sheet);color:var(--ig-text);
  border-radius:18px;overflow:hidden;
  box-shadow:0 18px 50px rgba(0,0,0,.35);
}
.ig-archive-sheet-preview{
  width:auto;max-width:100%;margin:0;background:transparent;position:relative;overflow:hidden;line-height:0;
}
.ig-archive-sheet-preview img,
.ig-archive-sheet-preview video{
  width:auto;height:auto;max-width:min(92vw,420px);max-height:min(72svh,720px);
  object-fit:contain;display:block;background:transparent;
}
.ig-archive-sheet-preview .ig-archive-fallback{
  position:relative;inset:auto;width:min(92vw,420px);height:auto;min-height:160px;
  display:flex;align-items:flex-end;justify-content:flex-start;
  padding:18px;box-sizing:border-box;overflow:hidden;
  background:linear-gradient(180deg,#2a2a2a 0%,#111 100%);
  color:#fff;font-size:15px;font-weight:600;line-height:1.35;
}
.ig-archive-sheet-preview .ig-archive-fallback span{
  display:-webkit-box;-webkit-line-clamp:8;-webkit-box-orient:vertical;
  overflow:hidden;word-break:break-word;overflow-wrap:anywhere;text-align:left;
}
.ig-archive-sheet-actions{
  flex:0 0 auto;display:flex;flex-direction:column;width:100%;
  background:var(--ig-sheet);z-index:3;
}
.ig-archive-sheet-btn{
  width:100%;border:0;background:transparent;color:var(--ig-text);
  padding:15px 16px;font-size:15px;font-weight:600;
  border-top:1px solid var(--ig-line);cursor:pointer;text-align:center;
}
.ig-archive-sheet-btn.is-danger{color:var(--ig-danger)}
.ig-archive-sheet-btn:disabled{opacity:.55;cursor:wait}
.ig-archive-toast{
  position:fixed;left:50%;bottom:28px;transform:translateX(-50%);
  background:#262626;color:#fff;padding:11px 16px;border-radius:999px;
  font-size:13px;font-weight:600;z-index:14100;opacity:0;pointer-events:none;max-width:min(92vw,360px);
}
.ig-archive-toast.is-on{opacity:1}
@media (min-width:1024px){
  .ig-archive-viewer{align-items:center;padding:32px}
  .ig-archive-sheet{max-width:min(680px,70vw);border-radius:22px}
  .ig-archive-sheet-preview img,
  .ig-archive-sheet-preview video{max-width:min(70vw,680px);max-height:min(78svh,960px)}
}
</style>
