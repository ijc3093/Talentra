<?php
/**
 * Gallery-style post viewer chrome (match profile Gallery grid modal).
 * Include on feed / public / shared overlays so View-the-post matches Gallery.
 */
?>
<style id="msb-post-viewer-gallery-chrome">
/* Full-bleed black stage like Gallery */
#pvOverlay.pv-overlay,
#pcmViewPostOverlay.pcm-view-post-overlay{
  background:#000 !important;
  background-color:#000 !important;
}
#pvOverlay.pv-overlay.show{
  padding:24px;
  align-items:center;
  justify-content:center;
}
#pvOverlay .pv-modal{
  width:fit-content;
  max-width:min(1320px,96vw);
  height:min(720px,88vh);
  background:transparent !important;
  overflow:hidden;
  display:flex;
  align-items:stretch;
  gap:0;
  box-shadow:none !important;
  border-radius:0;
}
#pvOverlay .pv-left{
  flex:0 1 auto;
  width:auto;
  min-width:0;
  max-width:min(720px, calc(96vw - min(380px,38vw) - 48px));
  height:100%;
  background:#000 !important;
  background-color:#000 !important;
  display:flex;
  align-items:stretch;
  justify-content:center;
  overflow:hidden;
  position:relative;
}
#pvOverlay .pv-media{
  width:auto;
  max-width:100%;
  height:100%;
  display:flex;
  align-items:center;
  justify-content:center;
  background:#000 !important;
  background-color:#000 !important;
  position:relative;
  overflow:hidden;
}
#pvOverlay .pv-media > img,
#pvOverlay .pv-media > video,
#pvOverlay .pv-media .mf-media-slide > img,
#pvOverlay .pv-media .media-slide > img,
#pvOverlay .pv-media .mf-media-slide > video,
#pvOverlay .pv-media .media-slide > video{
  max-width:100%;
  max-height:100%;
  width:auto;
  height:auto;
  object-fit:contain;
  object-position:center center;
}
#pvOverlay .pv-media > video,
#pvOverlay .pv-media .mf-media-slide > video,
#pvOverlay .pv-media .media-slide > video{
  width:100%;
  height:100%;
}

/* Desktop: landscape fills media column; portrait hugs image width */
@media (min-width: 901px){
  #pvOverlay.pv-overlay.show .pv-modal{
    width:min(1320px,96vw) !important;
    max-width:min(1320px,96vw) !important;
  }
  #pvOverlay.pv-overlay.show .pv-left{
    flex:1 1 0 !important;
    width:auto !important;
    min-width:0 !important;
    max-width:none !important;
    justify-content:center !important;
  }
  #pvOverlay.pv-overlay.show .pv-media{
    width:100% !important;
    max-width:100% !important;
    height:100% !important;
  }
  #pvOverlay.pv-overlay.show.pv-is-portrait .pv-modal{
    width:fit-content !important;
    max-width:min(1320px,96vw) !important;
  }
  #pvOverlay.pv-overlay.show.pv-is-portrait .pv-left{
    flex:0 1 auto !important;
    width:auto !important;
    max-width:min(56vh, 520px) !important;
  }
  #pvOverlay.pv-overlay.show.pv-is-portrait .pv-media{
    width:auto !important;
    max-width:100% !important;
  }
  #pvOverlay.pv-overlay.show.pv-is-portrait .pv-media > img,
  #pvOverlay.pv-overlay.show.pv-is-portrait .pv-media > video,
  #pvOverlay.pv-overlay.show.pv-is-portrait .pv-media .mf-media-slide > img,
  #pvOverlay.pv-overlay.show.pv-is-portrait .pv-media .media-slide > img,
  #pvOverlay.pv-overlay.show.pv-is-portrait .pv-media .mf-media-slide > video,
  #pvOverlay.pv-overlay.show.pv-is-portrait .pv-media .media-slide > video{
    width:auto !important;
    max-width:100% !important;
    height:100% !important;
    max-height:100% !important;
    object-fit:contain !important;
  }
  #pvOverlay.pv-overlay.show.pv-is-landscape .pv-left{
    flex:1 1 0 !important;
    max-width:none !important;
  }
}

/* Comments column — appearance paper + clear vertical divider */
#pvOverlay .pv-right{
  flex:0 0 min(380px,38vw);
  width:min(380px,38vw);
  min-width:280px;
  display:flex;
  flex-direction:column;
  background:var(--msb-palette-bg, #f2f1e8) !important;
  color:var(--msb-palette-text, #0f172a);
  min-height:0;
  border-radius:0 12px 12px 0;
  overflow:hidden;
  box-shadow:none !important;
  border-left:1px solid var(--msb-palette-border, rgba(15,23,42,.18)) !important;
}
#pvOverlay .pv-head,
#pvOverlay .pv-body,
#pvOverlay .pv-actions,
#pvOverlay .pv-input{
  background:var(--msb-palette-bg, #f2f1e8) !important;
  color:var(--msb-palette-text, #0f172a);
}
#pvOverlay .pv-head{
  border-bottom:1px solid var(--msb-palette-border, rgba(15,23,42,.08));
}
#pvOverlay .pv-actions{
  border-top:1px solid var(--msb-palette-border, rgba(15,23,42,.08));
}
#pvOverlay .pv-input::before{
  background:linear-gradient(to top, var(--msb-palette-bg, #f2f1e8), rgba(255,255,255,0)) !important;
}
#pvOverlay .pv-name{color:var(--msb-palette-text, #0f172a);}
#pvOverlay .pv-name.is-sharing-with{white-space:normal;overflow:visible;text-overflow:unset;line-height:1.25;}
#pvOverlay .pv-name .msb-sharing-with{font-weight:400;color:var(--msb-palette-text-muted, rgba(15,23,42,.55));}
#pvOverlay .pv-name a.msb-sharing-who{color:inherit;font-weight:700;text-decoration:none;}
#pvOverlay .pv-name a.msb-sharing-who:hover{text-decoration:underline;}
#pvOverlay .pv-meta{color:var(--msb-palette-text-muted, rgba(15,23,42,.55));}
#pvOverlay .pv-dots{color:var(--msb-palette-icon, inherit);}

/* Public / fallback iframe shell — same black stage */
#pcmViewPostOverlay.pcm-view-post-overlay{
  padding:24px !important;
  align-items:center !important;
  justify-content:center !important;
}
#pcmViewPostOverlay .pcm-view-post-frame{
  width:min(1320px,96vw) !important;
  height:min(720px,88vh) !important;
  max-width:100% !important;
  max-height:100% !important;
  border:0 !important;
  border-radius:12px !important;
  background:#000 !important;
  box-shadow:0 30px 90px rgba(0,0,0,.45) !important;
}
@media (max-width:767.98px){
  #pvOverlay.pv-overlay.show{padding:10px;}
  #pcmViewPostOverlay.pcm-view-post-overlay{padding:10px !important;}
  #pvOverlay .pv-right{
    min-width:0;
    width:100%;
    flex:1 1 auto;
    border-radius:0 0 12px 12px;
    border-left:0 !important;
    border-top:1px solid var(--msb-palette-border, rgba(15,23,42,.18)) !important;
  }
}
</style>
