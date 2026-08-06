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
  width:min(100%,var(--post-media-card-width,620px)) !important;
  margin-left:0 !important;
  margin-right:auto !important;
  aspect-ratio:auto !important;
  height:auto !important;
  min-height:180px !important;
}
body.public-page.feed-insta-ui .public-post-card.is-single-video-post .media-stage.standard-video-stage:not(.mf-media-sized),
body.public-page.feed-insta-ui .public-post-card.is-single-image-post .media-stage.standard-image-stage:not(.mf-media-sized){
  display:block !important;
  width:min(100%,var(--post-media-card-width,620px)) !important;
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

/* For You tab: keep the real author/menu and reaction controls out of view
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

/* For You must not reserve bordered rows for posts whose media is not ready.
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
