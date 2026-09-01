<?php
declare(strict_types=1);
?>
/* Final shared sizing for the chrome used by feed.php and public.php. */
body.feed-page.feed-insta-ui,
body.public-page.feed-insta-ui{
  --msb-feed-chrome-size:40px !important;
  --msb-top-action-h:44px !important;
  --msb-top-story-item:50px !important;
  --msb-top-story-ring:44px !important;
  --msb-top-story-name-size:11px !important;
}
body.feed-page.feed-insta-ui .ig-feed-header,
body.public-page.feed-insta-ui .ig-feed-header{
  width:100% !important;
  margin:0 !important;
  padding:16px 0 14px !important;
  align-items:flex-start !important;
  justify-content:center !important;
  box-sizing:border-box !important;
}
body.feed-page.feed-insta-ui .ig-stories-wrap,
body.public-page.feed-insta-ui .ig-stories-wrap{
  display:block !important;
  width:100% !important;
  max-width:614px !important;
  margin:0 auto !important;
}
body.feed-page.feed-insta-ui .feed-desktop-layout,
body.public-page.feed-insta-ui .feed-desktop-layout{
  width:100% !important;
  max-width:none !important;
  margin:0 !important;
  padding:0 !important;
  box-sizing:border-box !important;
}
body.feed-page.feed-insta-ui .feed-desktop-center,
body.public-page.feed-insta-ui .feed-desktop-center{
  width:614px !important;
  min-width:614px !important;
  max-width:614px !important;
  /* Center in the main panel (like stories); never slide under the left nav. */
  margin-left:max(
    var(--feed-center-left, calc(8px + var(--feed-left-nav-w, 236px) + var(--feed-side-gap, 28px))),
    calc((100% - 614px) / 2)
  ) !important;
  margin-right:auto !important;
  padding:0 !important;
  box-sizing:border-box !important;
}
body.feed-page.feed-insta-ui .ig-stories-track,
body.public-page.feed-insta-ui .ig-stories-track{
  gap:18px !important;
  padding:0 2px 2px !important;
}
body.feed-page.feed-insta-ui .ig-stories-track.is-empty,
body.public-page.feed-insta-ui .ig-stories-track.is-empty{
  min-height:44px !important;
}
body.feed-page.feed-insta-ui .ig-story-item,
body.public-page.feed-insta-ui .ig-story-item{
  width:50px !important;
  min-width:50px !important;
}
body.feed-page.feed-insta-ui .ig-story-ring,
body.public-page.feed-insta-ui .ig-story-ring{
  width:44px !important;
  height:44px !important;
  margin:0 auto 4px !important;
  padding:2px !important;
  box-sizing:border-box !important;
}
body.feed-page.feed-insta-ui .ig-story-create .ig-story-ring-create i,
body.public-page.feed-insta-ui .ig-story-create .ig-story-ring-create i,
body.feed-page.feed-insta-ui .ig-story-empty-icon,
body.public-page.feed-insta-ui .ig-story-empty-icon{
  font-size:18px !important;
  line-height:1 !important;
}
body.feed-page.feed-insta-ui .ig-feed-user-name,
body.public-page.feed-insta-ui .ig-feed-user-name{
  min-height:44px !important;
  font-size:clamp(24px,2.6vw,32px) !important;
  line-height:1 !important;
  font-weight:400 !important;
}
body.feed-page.feed-insta-ui .ig-feed-top-lead,
body.public-page.feed-insta-ui .ig-feed-top-lead{
  left:16px !important;
}
body.feed-page.feed-insta-ui .ig-feed-top-actions,
body.public-page.feed-insta-ui .ig-feed-top-actions{
  right:16px !important;
  gap:10px !important;
}

/* Search sits on the right, just under the header border (not in the top icon row). */
body.feed-page.feed-insta-ui .feed-side-search,
body.public-page.feed-insta-ui .feed-side-search{
  display:none !important;
}
@media (min-width:1025px){
  body.feed-page.feed-insta-ui .feed-side-search,
  body.public-page.feed-insta-ui .feed-side-search{
    display:block !important;
    position:fixed !important;
    /* Sit just right of the center column edge (small inset, not full rail gap). */
    left:calc(
      var(--feed-mainpanel-left, var(--feedRailW, 84px))
      + max(
          var(--feed-center-left, calc(8px + var(--feed-left-nav-w, 236px) + var(--feed-side-gap, 28px))),
          (100vw - var(--feed-mainpanel-left, var(--feedRailW, 84px)) - var(--feed-center-w, 614px)) / 2
        )
      + var(--feed-center-w, 614px)
      + 10px
    ) !important;
    right:auto !important;
    top:98px !important;
    width:min(360px, var(--feed-right-rail-w, 248px) + 110px) !important;
    max-width:calc(100vw - 24px) !important;
    z-index:110 !important;
    margin:0 !important;
    padding:0 !important;
    box-sizing:border-box !important;
  }
  body.feed-page.feed-insta-ui .feed-side-search-form,
  body.public-page.feed-insta-ui .feed-side-search-form{
    display:block !important;
    width:100% !important;
    margin:0 !important;
  }
  body.feed-page.feed-insta-ui .feed-side-search .feed-top-search-input,
  body.public-page.feed-insta-ui .feed-side-search .feed-top-search-input{
    width:100% !important;
    height:42px !important;
    min-height:42px !important;
    border-radius:999px !important;
    background:var(--feed-control-bg, var(--public-control-bg, var(--msb-palette-bg, #fff))) !important;
    color:var(--feed-topbar-text, var(--public-text, var(--msb-palette-text, #0d0d0d))) !important;
    border:1px solid var(--feed-control-border, var(--public-border, var(--msb-palette-border-strong, #c0c2c4))) !important;
    box-sizing:border-box !important;
  }
  body.feed-page.feed-insta-ui .feed-side-search .feed-top-search-input::placeholder,
  body.public-page.feed-insta-ui .feed-side-search .feed-top-search-input::placeholder{
    color:var(--feed-control-placeholder, var(--public-muted, var(--msb-palette-text-muted, #667085))) !important;
  }
  body.feed-page.feed-insta-ui .feed-side-search .feed-top-search-icon,
  body.public-page.feed-insta-ui .feed-side-search .feed-top-search-icon{
    color:var(--feed-control-placeholder, var(--public-muted, var(--msb-palette-icon, #667085))) !important;
  }
  body.feed-page.feed-insta-ui .feed-top-search--tabs-only,
  body.public-page.feed-insta-ui .feed-top-search--tabs-only{
    padding-top:12px !important;
  }
  body.feed-page.feed-insta-ui .feed-top-tabs-row,
  body.public-page.feed-insta-ui .feed-top-tabs-row{
    display:flex !important;
    align-items:center !important;
    gap:12px !important;
    width:100% !important;
  }
  body.feed-page.feed-insta-ui .feed-top-tabs-row .feed-discover-tabs,
  body.public-page.feed-insta-ui .feed-top-tabs-row .feed-discover-tabs{
    flex:1 1 auto !important;
    min-width:0 !important;
  }
}
@media (max-width:1024px){
  body.feed-page.feed-insta-ui .feed-side-search,
  body.public-page.feed-insta-ui .feed-side-search{
    display:block !important;
    position:relative !important;
    left:auto !important;
    top:auto !important;
    width:100% !important;
    max-width:614px !important;
    margin:0 auto !important;
    padding:12px 16px 0 !important;
    box-sizing:border-box !important;
    z-index:1 !important;
  }
}
body.feed-page.feed-insta-ui .ig-top-shop,
body.feed-page.feed-insta-ui .ig-top-cart,
body.feed-page.feed-insta-ui .ig-top-mic,
body.public-page.feed-insta-ui .ig-top-shop,
body.public-page.feed-insta-ui .ig-top-cart,
body.public-page.feed-insta-ui .ig-top-mic{
  width:44px !important;
  height:44px !important;
  min-width:44px !important;
  min-height:44px !important;
  padding:0 !important;
}
body.feed-page.feed-insta-ui .ig-top-shop i,
body.feed-page.feed-insta-ui .ig-top-cart i,
body.public-page.feed-insta-ui .ig-top-shop i,
body.public-page.feed-insta-ui .ig-top-cart i{
  font-size:18px !important;
  line-height:1 !important;
}
body.feed-page.feed-insta-ui .ig-top-mic i,
body.public-page.feed-insta-ui .ig-top-mic i{
  font-size:12px !important;
  line-height:1 !important;
}
body.feed-page.feed-insta-ui .ig-top-live,
body.public-page.feed-insta-ui .ig-top-live{
  height:44px !important;
  min-height:44px !important;
  padding:0 18px !important;
  gap:8px !important;
  font-family:"Roboto","Helvetica Neue",Arial,sans-serif !important;
  font-size:15px !important;
  line-height:1 !important;
  font-weight:800 !important;
  font-style:normal !important;
  letter-spacing:-.01em !important;
  font-synthesis:none !important;
}
body.feed-page.feed-insta-ui .ig-top-live i,
body.public-page.feed-insta-ui .ig-top-live i{
  width:16px !important;
  height:16px !important;
  font-size:16px !important;
  line-height:1 !important;
}
body.feed-page.feed-insta-ui .feed-top-search,
body.public-page.feed-insta-ui .feed-top-search{
  width:100% !important;
  min-width:0 !important;
  max-width:100% !important;
  margin:0 !important;
  padding:12px 16px 8px !important;
  box-sizing:border-box !important;
}
body.feed-page.feed-insta-ui .feed-top-search-row,
body.public-page.feed-insta-ui .feed-top-search-row{
  display:flex !important;
  align-items:center !important;
  width:100% !important;
  min-width:0 !important;
  gap:16px !important;
  box-sizing:border-box !important;
}
body.feed-page.feed-insta-ui .feed-top-search-form,
body.public-page.feed-insta-ui .feed-top-search-form{
  flex:1 1 auto !important;
  width:auto !important;
  min-width:0 !important;
  max-width:none !important;
  margin:0 !important;
}
body.feed-page.feed-insta-ui .feed-top-search-input,
body.public-page.feed-insta-ui .feed-top-search-input{
  height:42px !important;
  min-height:42px !important;
  font-family:"Roboto","Helvetica Neue",Arial,sans-serif !important;
  font-size:14px !important;
  line-height:1.2 !important;
  font-weight:400 !important;
  font-style:normal !important;
  letter-spacing:normal !important;
  font-synthesis:none !important;
}
body.feed-page.feed-insta-ui .feed-top-search-input::placeholder,
body.public-page.feed-insta-ui .feed-top-search-input::placeholder{
  font-family:"Roboto","Helvetica Neue",Arial,sans-serif !important;
  font-size:14px !important;
  line-height:1.2 !important;
  font-weight:400 !important;
  opacity:1 !important;
}
body.feed-page.feed-insta-ui .feed-top-search-icon,
body.public-page.feed-insta-ui .feed-top-search-icon{
  width:32px !important;
  height:32px !important;
  min-width:32px !important;
  min-height:32px !important;
  padding:0 !important;
}
body.feed-page.feed-insta-ui .feed-top-search-icon i,
body.public-page.feed-insta-ui .feed-top-search-icon i{
  display:inline-flex !important;
  align-items:center !important;
  justify-content:center !important;
  width:15px !important;
  height:15px !important;
  font-size:15px !important;
  line-height:1 !important;
}
body.feed-page.feed-insta-ui .feed-top-search-settings,
body.public-page.feed-insta-ui .feed-top-search-settings{
  display:none !important;
}
body.feed-page.feed-insta-ui .feed-discover-tabs,
body.public-page.feed-insta-ui .feed-discover-tabs{
  width:100% !important;
  min-width:0 !important;
  max-width:100% !important;
  margin-left:0 !important;
  margin-right:0 !important;
  box-sizing:border-box !important;
}
body.feed-page.feed-insta-ui .feed-top-search-settings i,
body.public-page.feed-insta-ui .feed-top-search-settings i{
  font-size:13px !important;
  line-height:1 !important;
}
body.feed-page.feed-insta-ui .feed-left-nav-item,
body.public-page.feed-insta-ui .feed-left-nav-item,
body.feed-page.feed-insta-ui .feed-right-nav-item,
body.public-page.feed-insta-ui .feed-right-nav-item{
  gap:12px !important;
  min-height:42px !important;
  padding:8px 12px !important;
  font-size:14px !important;
  font-weight:500 !important;
  line-height:1.2 !important;
  box-sizing:border-box !important;
}
body.feed-page.feed-insta-ui .feed-left-nav-ic,
body.public-page.feed-insta-ui .feed-left-nav-ic,
body.feed-page.feed-insta-ui .feed-right-nav-ic,
body.public-page.feed-insta-ui .feed-right-nav-ic{
  width:20px !important;
  height:20px !important;
  flex:0 0 20px !important;
}
body.feed-page.feed-insta-ui .feed-left-nav-ic svg,
body.public-page.feed-insta-ui .feed-left-nav-ic svg,
body.feed-page.feed-insta-ui .feed-right-nav-ic svg,
body.public-page.feed-insta-ui .feed-right-nav-ic svg{
  width:18px !important;
  height:18px !important;
}
body.feed-page.feed-insta-ui .feed-desktop-center > .mf-feed,
body.public-page.feed-insta-ui .feed-desktop-center > .ig-feed,
body.feed-page.feed-insta-ui .mf-card,
body.public-page.feed-insta-ui .public-post-card{
  /*
   * Do not let late image/video dimensions change the browser's scroll
   * anchor and make the current post appear to jump after navigation.
   */
  overflow-anchor:none !important;
}
body.feed-page.feed-insta-ui .mf-feed.mf-hydrating,
body.public-page.feed-insta-ui .ig-feed.public-media-hydrating{
  position:relative !important;
  display:block !important;
  visibility:visible !important;
  min-height:0 !important;
  pointer-events:none !important;
  overflow:hidden !important;
}
body.feed-page.feed-insta-ui .mf-feed.mf-hydrating > *,
body.public-page.feed-insta-ui .ig-feed.public-media-hydrating > *{
  visibility:hidden !important;
}
body.feed-page.feed-insta-ui .mf-feed.mf-hydrating::before,
body.public-page.feed-insta-ui .ig-feed.public-media-hydrating::before{
  content:none !important;
  display:none !important;
}
body.feed-page.feed-insta-ui .mf-feed.mf-hydrating::after,
body.public-page.feed-insta-ui .ig-feed.public-media-hydrating::after{
  content:none !important;
  display:none !important;
}

/*
 * Keep every post in document order while its image/video is loading.
 * Removing a pending card with display:none lets the following card occupy
 * the top position, then pushes it down when the newer card becomes ready.
 */
body.feed-page.feed-insta-ui .mf-card.is-single-video-post:not(.mf-video-ready),
body.feed-page.feed-insta-ui .mf-card.is-single-image-post:not(.mf-image-ready),
body.feed-page.feed-insta-ui .mf-card.is-single-video-post.mf-video-error,
body.feed-page.feed-insta-ui .mf-card.is-single-image-post.mf-image-error,
body.public-page.feed-insta-ui .public-post-card.is-single-video-post:not(.mf-video-ready),
body.public-page.feed-insta-ui .public-post-card.is-single-image-post:not(.mf-image-ready){
  display:block !important;
  visibility:visible !important;
  pointer-events:auto !important;
}
body.feed-page.feed-insta-ui .mf-card.is-single-video-post .media-stage.standard-video-stage:not(.mf-media-sized),
body.feed-page.feed-insta-ui .mf-card.is-single-image-post .media-stage.standard-image-stage:not(.mf-media-sized){
  display:block !important;
  width:min(100%,var(--post-media-card-width,100%)) !important;
  margin-left:0 !important;
  margin-right:auto !important;
  aspect-ratio:auto !important;
  height:auto !important;
  min-height:180px !important;
}
body.public-page.feed-insta-ui .public-post-card.is-single-video-post .media-stage.standard-video-stage:not(.mf-media-sized),
body.public-page.feed-insta-ui .public-post-card.is-single-image-post .media-stage.standard-image-stage:not(.mf-media-sized){
  display:block !important;
  width:min(100%,var(--post-media-card-width,100%)) !important;
  margin-left:0 !important;
  margin-right:auto !important;
  aspect-ratio:auto !important;
  height:auto !important;
  min-height:180px !important;
}
body.feed-page.feed-insta-ui .mf-card.single-landscape .media-stage:not(.mf-media-sized),
body.public-page.feed-insta-ui .public-post-card.single-landscape .media-stage:not(.mf-media-sized),
body.feed-page.feed-insta-ui .mf-card.single-portrait .media-stage:not(.mf-media-sized),
body.public-page.feed-insta-ui .public-post-card.single-portrait .media-stage:not(.mf-media-sized){
  aspect-ratio:auto !important;
}
body.feed-page.feed-insta-ui .mf-card.is-single-video-post:not(.mf-video-ready) .media-stage > video,
body.feed-page.feed-insta-ui .mf-card.is-single-image-post:not(.mf-image-ready) .media-stage > img,
body.public-page.feed-insta-ui .public-post-card.is-single-video-post:not(.mf-video-ready) .media-stage > video,
body.public-page.feed-insta-ui .public-post-card.is-single-image-post:not(.mf-image-ready) .media-stage > img{
  display:block !important;
  visibility:visible !important;
  opacity:1 !important;
}

/* Final loading-cover lock: no real avatar, author, menu, media, or actions
   may bleed through the skeleton while the first frame is pending. */
body.feed-page.feed-insta-ui .mf-feed.mf-hydrating > .mf-card,
body.feed-page.feed-insta-ui .mf-feed.mf-hydrating > .mf-feed-empty,
body.public-page.feed-insta-ui .ig-feed.public-media-hydrating > .public-post-card,
body.public-page.feed-insta-ui .ig-feed.public-media-hydrating > .empty-state{
  visibility:hidden !important;
  opacity:0 !important;
  pointer-events:none !important;
}

/* Public tab: while a standard image/video is still pending, do not expose
   the real author row, overflow menu, follow control, or reaction toolbar
   over the empty media surface. The media itself remains available to load. */
body.public-page.feed-insta-ui .public-post-card.is-single-video-post:not(.mf-frame-painted):not(.mf-video-error) .standard-media-topbar,
body.public-page.feed-insta-ui .public-post-card.is-single-video-post:not(.mf-frame-painted):not(.mf-video-error) .standard-media-top-actions,
body.public-page.feed-insta-ui .public-post-card.is-single-video-post:not(.mf-frame-painted):not(.mf-video-error) .standard-media-bottom,
body.public-page.feed-insta-ui .public-post-card.is-single-image-post:not(.mf-image-ready):not(.mf-image-error) .standard-media-topbar,
body.public-page.feed-insta-ui .public-post-card.is-single-image-post:not(.mf-image-ready):not(.mf-image-error) .standard-media-top-actions,
body.public-page.feed-insta-ui .public-post-card.is-single-image-post:not(.mf-image-ready):not(.mf-image-error) .standard-media-bottom{
  visibility:hidden !important;
  opacity:0 !important;
  pointer-events:none !important;
}

/* Love / comment / thumbs / save / share sit below the media (display:contents).
   Once the video is shown, keep that bar usable even if the first-frame
   callback never fires. Do not change the action-bar flex layout. */
body.public-page.feed-insta-ui .ig-feed:not(.public-media-hydrating) .public-post-card.mf-video-ready .standard-media-bottom,
body.public-page.feed-insta-ui .ig-feed:not(.public-media-hydrating) .public-post-card.mf-video-ready .standard-media-bottom > *,
body.public-page.feed-insta-ui .ig-feed:not(.public-media-hydrating) .public-post-card.mf-video-ready .standard-media-actions,
body.public-page.feed-insta-ui .ig-feed:not(.public-media-hydrating) .public-post-card.mf-video-ready .standard-media-actions a,
body.public-page.feed-insta-ui .ig-feed:not(.public-media-hydrating) .public-post-card.mf-video-ready .standard-media-actions button,
body.public-page.feed-insta-ui .ig-feed:not(.public-media-hydrating) .public-post-card.mf-video-ready .standard-media-actions span{
  visibility:visible !important;
  opacity:1 !important;
  pointer-events:auto !important;
}

/* Circle tab: keep the real author/menu and reaction controls out of view
   until the pending image or first video frame has painted. */
body.feed-page.feed-insta-ui .mf-card.is-single-video-post:not(.mf-frame-painted):not(.mf-video-error) .mf-head--on-media,
body.feed-page.feed-insta-ui .mf-card.is-single-video-post:not(.mf-frame-painted):not(.mf-video-error) .mf-media-top-actions,
body.feed-page.feed-insta-ui .mf-card.is-single-video-post:not(.mf-frame-painted):not(.mf-video-error) .mf-actions,
body.feed-page.feed-insta-ui .mf-card.is-single-image-post:not(.mf-image-ready):not(.mf-image-error) .mf-head--on-media,
body.feed-page.feed-insta-ui .mf-card.is-single-image-post:not(.mf-image-ready):not(.mf-image-error) .mf-media-top-actions,
body.feed-page.feed-insta-ui .mf-card.is-single-image-post:not(.mf-image-ready):not(.mf-image-error) .mf-actions{
  visibility:hidden !important;
  opacity:0 !important;
  pointer-events:none !important;
}

/* Circle must not reserve bordered rows for posts whose media is not ready.
   The card returns to normal document flow as soon as its ready class lands. */
body.feed-page.feed-insta-ui .mf-card.is-single-video-post:not(.mf-video-ready):not(.mf-video-error),
body.feed-page.feed-insta-ui .mf-card.is-single-image-post:not(.mf-image-ready):not(.mf-image-error){
  display:none !important;
}

/* Discover / public.php: same rule — never leave empty dark media shells after
   create-post redirect while the first frame is still loading. */
body.public-page.feed-insta-ui .public-post-card.is-single-video-post:not(.mf-video-ready):not(.mf-video-error),
body.public-page.feed-insta-ui .public-post-card.is-single-image-post:not(.mf-image-ready):not(.mf-image-error){
  display:none !important;
}

@media (max-width:1024px){
  body.feed-page.feed-insta-ui .feed-desktop-center,
  body.public-page.feed-insta-ui .feed-desktop-center{
    width:100% !important;
    min-width:0 !important;
    max-width:100% !important;
    margin-left:auto !important;
    margin-right:auto !important;
  }
}

/* Home / public: header bottom border spans to the icon-rail vertical line. */
@media (min-width:1025px){
  body.public-page.feed-insta-ui .sh-mainpanel,
  body.home-page.feed-insta-ui .sh-mainpanel{
    margin-left:0 !important;
    width:100% !important;
    max-width:100% !important;
  }
  body.public-page.feed-insta-ui .ig-feed-header,
  body.home-page.feed-insta-ui .ig-feed-header{
    width:100% !important;
    margin:0 !important;
    padding:16px 16px 14px calc(var(--feedRailW, 84px) + 16px) !important;
    box-sizing:border-box !important;
    border-bottom:1px solid var(--msb-palette-border-strong, #d1d5db) !important;
  }
  body.public-page.feed-insta-ui .ig-feed-top-lead,
  body.home-page.feed-insta-ui .ig-feed-top-lead{
    left:calc(var(--feedRailW, 84px) + 16px) !important;
  }
  body.public-page.feed-insta-ui .feed-desktop-layout,
  body.home-page.feed-insta-ui .feed-desktop-layout{
    margin-left:var(--feedRailW, 84px) !important;
    width:calc(100% - var(--feedRailW, 84px)) !important;
    max-width:calc(100% - var(--feedRailW, 84px)) !important;
  }
  /* Center Entertainment…Sign Out in the column between icon rail and feed. */
  body.feed-insta-ui{
    --feed-left-column-w:max(
      var(--feed-center-left, calc(8px + var(--feed-left-nav-w, 236px) + var(--feed-side-gap, 28px))),
      calc((100vw - var(--feed-mainpanel-left, var(--feedRailW, 84px)) - var(--feed-center-w, 614px)) / 2)
    );
  }
  body.feed-insta-ui:not(.shop-page) .feed-left-rail{
    left:calc(var(--feedRailW, 84px) + 6px) !important;
    top:var(--feed-left-rail-top, 88px) !important;
    width:var(--feed-left-column-w) !important;
    max-width:var(--feed-left-column-w) !important;
    height:calc(100vh - var(--feed-left-rail-top, 88px) - 16px) !important;
    max-height:calc(100vh - var(--feed-left-rail-top, 88px) - 16px) !important;
    padding-top:0 !important;
    padding-bottom:8px !important;
    overflow:hidden !important;
    z-index:90 !important;
  }
  body.feed-insta-ui:not(.shop-page) .feed-left-rail-head{
    position:relative !important;
    flex:0 0 auto !important;
    align-self:flex-start !important;
    left:auto !important;
    top:auto !important;
    width:auto !important;
    max-width:260px !important;
    min-height:48px !important;
    display:flex !important;
    align-items:center !important;
    z-index:3 !important;
    margin:0 !important;
    background:var(--msb-palette-bg, var(--public-surface, #fff)) !important;
  }
  body.feed-insta-ui:not(.shop-page) .feed-left-rail-head .feed-left-nav-add-program{
    display:flex !important;
    visibility:visible !important;
    opacity:1 !important;
    color:var(--msb-palette-text, var(--msb-fries, #f3f6fb)) !important;
    width:auto !important;
    max-width:260px !important;
    border:0 !important;
    background:transparent !important;
    cursor:pointer !important;
    padding:10px 12px !important;
    gap:12px !important;
    min-height:48px !important;
    font-size:16px !important;
    font-weight:600 !important;
    line-height:1.2 !important;
  }
  body.feed-insta-ui:not(.shop-page) .feed-left-rail-head .feed-left-nav-add-program .feed-left-nav-ic{
    width:24px !important;
    height:24px !important;
    flex:0 0 24px !important;
  }
  body.feed-insta-ui:not(.shop-page) .feed-left-rail-head .feed-left-nav-add-program .feed-left-nav-ic svg{
    width:22px !important;
    height:22px !important;
    max-width:22px !important;
    max-height:22px !important;
  }
  body.feed-insta-ui:not(.shop-page) .feed-left-rail-head .feed-left-nav-add-program .feed-left-nav-label{
    font-size:16px !important;
    font-weight:600 !important;
  }
  body.feed-insta-ui:not(.shop-page) .feed-left-rail > .feed-left-nav{
    position:relative !important;
    z-index:1 !important;
    width:var(--feed-left-nav-w, 236px) !important;
    max-width:100% !important;
    margin-left:auto !important;
    margin-right:auto !important;
    padding-top:0 !important;
  }
  body.feed-insta-ui:not(.shop-page) .feed-left-rail-footer{
    flex:0 0 auto !important;
    align-self:flex-start !important;
    margin-top:auto !important;
    width:auto !important;
  }
  body.feed-insta-ui #ttLeftbarOverlays{
    width:var(--feed-left-column-w) !important;
  }
  body.feed-insta-ui #ttLeftbarOverlays .tt-menu-wrap{
    align-items:center !important;
  }
  body.feed-insta-ui #ttLeftbarOverlays .tt-menu-head{
    align-self:stretch !important;
    width:100% !important;
    box-sizing:border-box !important;
  }
  body.feed-insta-ui #ttLeftbarOverlays .tt-menu-panel{
    margin-left:auto !important;
    margin-right:auto !important;
  }
}
