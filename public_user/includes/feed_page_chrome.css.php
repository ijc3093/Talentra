<?php
declare(strict_types=1);
?>
/* [LEFT_RAIL_CHROME] — same left nav on every page (feed.php reference) */
.feed-ig-rail{
  --msb-feed-chrome-size:40px;
  --msb-feed-chrome-font:14px;
  --msb-feed-chrome-icon:16px;
  --msb-feed-chrome-circle:50%;
  background-color:var(--msb-palette-bg, #f5f7fb) !important;
  color:var(--msb-palette-text-on-nav, var(--msb-palette-text, #111827)) !important;
  border-radius:0 !important;
  border-right:1px solid var(--msb-palette-border-strong, #d1d5db) !important;
}
.feed-ig-rail .feed-ig-logo{
  width:var(--msb-feed-chrome-size) !important;
  height:var(--msb-feed-chrome-size) !important;
  min-width:var(--msb-feed-chrome-size) !important;
  min-height:var(--msb-feed-chrome-size) !important;
  border-radius:var(--msb-feed-chrome-circle) !important;
  font-size:var(--msb-feed-chrome-font) !important;
  line-height:1 !important;
  box-shadow:none !important;
}
.feed-ig-rail .feed-ig-logo-label{
  display:block !important;
  font-size:11px !important;
  line-height:1.15 !important;
  font-weight:800 !important;
  max-width:72px !important;
  width:100% !important;
  margin-top:2px !important;
  color:var(--msb-palette-text-on-nav, var(--msb-palette-text, #0f172a)) !important;
  text-decoration:none !important;
}
.feed-ig-rail a.feed-ig-logo-label:hover,
.feed-ig-rail a.feed-ig-logo-label:focus{
  color:var(--msb-palette-link-hover, var(--msb-palette-link, #2563eb)) !important;
  text-decoration:none !important;
}
html.dark-auto .feed-ig-rail,
html[data-theme="dark"] .feed-ig-rail{
  background-color:var(--msb-palette-bg, #171d24) !important;
  color:var(--msb-palette-text-on-nav, var(--msb-palette-text, #f3f6fb)) !important;
}
html.dark-auto .feed-ig-rail .feed-ig-logo-label,
html[data-theme="dark"] .feed-ig-rail .feed-ig-logo-label{
  color:var(--msb-palette-text-on-nav, var(--msb-palette-text, #f3f6fb)) !important;
}
html[data-theme="light"] .feed-ig-rail{
  background-color:var(--msb-palette-bg, #f5f7fb) !important;
}
html[data-theme="light"] .feed-ig-rail .feed-ig-logo-label{
  color:var(--msb-palette-text-on-nav, var(--msb-palette-text, #0f172a)) !important;
}
.feed-ig-rail .feed-ig-btn,
.feed-ig-rail .feed-ig-link,
.feed-ig-rail .ig-link,
.feed-ig-rail button.feed-ig-link{
  width:var(--msb-feed-chrome-size) !important;
  height:var(--msb-feed-chrome-size) !important;
  min-width:var(--msb-feed-chrome-size) !important;
  min-height:var(--msb-feed-chrome-size) !important;
  border-radius:var(--msb-feed-chrome-circle) !important;
  font-size:var(--msb-feed-chrome-icon) !important;
  padding:0 !important;
}
.feed-ig-rail .feed-ig-btn .icon,
.feed-ig-rail .feed-ig-link .icon,
.feed-ig-rail .ig-link .icon,
.feed-ig-rail button.feed-ig-link .icon{
  font-size:var(--msb-feed-chrome-icon) !important;
  line-height:1 !important;
}
.feed-ig-rail .feed-ig-avatar .feed-ig-btn.js-open-profile-door{
  cursor:pointer;
  border:0;
  padding:0;
  width:var(--msb-feed-chrome-size) !important;
  height:var(--msb-feed-chrome-size) !important;
  min-width:var(--msb-feed-chrome-size) !important;
  min-height:var(--msb-feed-chrome-size) !important;
  border-radius:var(--msb-feed-chrome-circle) !important;
}
.feed-ig-rail .feed-ig-btn.ig-stories-menu-btn.feed-ig-menu-mobile,
.feed-ig-rail .ig-stories-menu-btn.feed-ig-menu-mobile{
  display:flex !important;
  align-items:center !important;
  justify-content:center !important;
  cursor:pointer;
  border:0;
  color:var(--msb-palette-text-on-nav, var(--msb-palette-text, #111827)) !important;
  background:transparent !important;
  width:var(--msb-feed-chrome-size) !important;
  height:var(--msb-feed-chrome-size) !important;
  min-width:var(--msb-feed-chrome-size) !important;
  min-height:var(--msb-feed-chrome-size) !important;
  border-radius:var(--msb-feed-chrome-circle) !important;
  padding:0 !important;
}
.feed-ig-rail .feed-ig-menu-mobile .fa,
.feed-ig-rail .ig-stories-menu-btn.feed-ig-menu-mobile .fa{
  font-size:var(--msb-feed-chrome-icon) !important;
  line-height:1 !important;
  color:var(--msb-palette-icon, var(--msb-palette-text-on-nav, currentColor)) !important;
}
.feed-ig-rail .feed-ig-btn.ig-stories-menu-btn.feed-ig-menu-mobile:hover,
.feed-ig-rail .feed-ig-btn.ig-stories-menu-btn.feed-ig-menu-mobile:focus,
.feed-ig-rail .ig-stories-menu-btn.feed-ig-menu-mobile:hover,
.feed-ig-rail .ig-stories-menu-btn.feed-ig-menu-mobile:focus{
  color:var(--msb-palette-text-on-nav-hover, var(--msb-palette-text-on-nav, var(--msb-palette-text, #111827))) !important;
  background:var(--msb-palette-nav-hover, rgba(255,255,255,.08)) !important;
  outline:none !important;
}
.feed-ig-rail .feed-ig-btn.ig-stories-menu-btn.feed-ig-menu-mobile:hover .fa,
.feed-ig-rail .feed-ig-btn.ig-stories-menu-btn.feed-ig-menu-mobile:focus .fa,
.feed-ig-rail .ig-stories-menu-btn.feed-ig-menu-mobile:hover .fa,
.feed-ig-rail .ig-stories-menu-btn.feed-ig-menu-mobile:focus .fa{
  color:var(--msb-palette-icon-on-nav-hover, var(--msb-palette-icon, currentColor)) !important;
}
html.dark-auto .feed-ig-rail .feed-ig-btn.ig-stories-menu-btn.feed-ig-menu-mobile,
html[data-theme="dark"] .feed-ig-rail .feed-ig-btn.ig-stories-menu-btn.feed-ig-menu-mobile,
html.dark-auto .feed-ig-rail .ig-stories-menu-btn.feed-ig-menu-mobile,
html[data-theme="dark"] .feed-ig-rail .ig-stories-menu-btn.feed-ig-menu-mobile,
html.dark-auto .feed-ig-rail .feed-ig-menu-mobile .fa,
html[data-theme="dark"] .feed-ig-rail .feed-ig-menu-mobile .fa{
  color:var(--msb-palette-text-on-nav, var(--msb-palette-text, #f3f6fb)) !important;
}
html[data-theme="light"] .feed-ig-rail .feed-ig-btn.ig-stories-menu-btn.feed-ig-menu-mobile,
html[data-theme="light"] .feed-ig-rail .ig-stories-menu-btn.feed-ig-menu-mobile,
html[data-theme="light"] .feed-ig-rail .feed-ig-menu-mobile .fa{
  color:var(--msb-palette-text-on-nav, var(--msb-palette-text, #0f172a)) !important;
}
.feed-ig-rail .feed-ig-avatar .feed-ig-btn.js-open-profile-door:focus-visible{
  outline:2px solid var(--msb-palette-link, #2563eb);
  outline-offset:2px;
}
.feed-ig-rail .feed-ig-avatar,
.feed-ig-rail .feed-ig-avatar > .feed-ig-btn,
.feed-ig-rail .feed-ig-avatar > .js-open-profile-door{
  width:var(--msb-feed-chrome-size) !important;
  height:var(--msb-feed-chrome-size) !important;
  min-width:var(--msb-feed-chrome-size) !important;
  min-height:var(--msb-feed-chrome-size) !important;
  border-radius:var(--msb-feed-chrome-circle) !important;
  padding:0 !important;
}
.feed-ig-rail .feed-ig-avatar .bestprofile-avatar,
.feed-ig-rail .feed-ig-btn .bestprofile-avatar{
  width:var(--msb-feed-chrome-size) !important;
  height:var(--msb-feed-chrome-size) !important;
  min-width:var(--msb-feed-chrome-size) !important;
  min-height:var(--msb-feed-chrome-size) !important;
  border-radius:var(--msb-feed-chrome-circle) !important;
  font-size:11px !important;
  box-shadow:none !important;
}
.feed-ig-rail .feed-ig-dot{
  right:4px !important;
  top:4px !important;
  width:7px !important;
  height:7px !important;
}
.feed-ig-rail .feed-ig-badge,
.feed-ig-rail .chatBadge{
  top:-3px !important;
  right:-2px !important;
  min-width:16px !important;
  height:16px !important;
  padding:0 4px !important;
  font-size:10px !important;
  border-width:1px !important;
}

/* Hide legacy header hamburger — menu lives on rail avatar */
.ig-feed-header .ig-stories-menu-btn{
  display:none !important;
}

/* [FEED_PAGE_CHROME] — equal-sized header controls; circles for stories/logo/actions */
body.feed-insta-ui,
body.feed-page.feed-insta-ui,
body.public-page.feed-insta-ui{
  --msb-feed-chrome-size:40px;
  --msb-feed-chrome-lead-font:18px;
  --msb-feed-chrome-font:14px;
  --msb-feed-chrome-icon:16px;
  --msb-feed-chrome-circle:50%;
  --msb-feed-chrome-pill:999px;
  --msb-feed-chrome-control:8px;
  /* Top stack — public.php reference */
  --msb-top-header-pad-top:16px;
  --msb-top-header-pad-bottom:14px;
  --msb-top-search-pad-top:12px;
  --msb-top-search-pad-x:16px;
  --msb-top-search-pad-bottom:8px;
  --msb-top-search-input-h:42px;
  --msb-top-story-item:72px;
  --msb-top-story-ring:66px;
  --msb-top-story-name-size:12px;
  --msb-top-action-h:44px;
  --msb-feed-user-name-font:clamp(24px, 2.6vw, 32px);
  --msb-feed-user-name-font-family:'Segoe Script','Apple Chancery','Bradley Hand',cursive;
  --msb-feed-user-name-menu-gap:14px;
}

/* Top header row — match public.php height */
body.feed-insta-ui .ig-feed-header,
body.feed-page.feed-insta-ui .ig-feed-header,
body.public-page.feed-insta-ui .ig-feed-header{
  align-items:flex-start !important;
  justify-content:center !important;
  padding:var(--msb-top-header-pad-top) 0 var(--msb-top-header-pad-bottom) !important;
  box-sizing:border-box !important;
}
body.feed-insta-ui .ig-stories-wrap,
body.feed-page.feed-insta-ui .ig-stories-wrap,
body.public-page.feed-insta-ui .ig-stories-wrap,
body.feed-insta-ui .ig-stories-bar,
body.feed-page.feed-insta-ui .ig-stories-bar,
body.public-page.feed-insta-ui .ig-stories-bar{
  align-items:flex-start !important;
}
body.feed-insta-ui .ig-feed-top-lead,
body.feed-page.feed-insta-ui .ig-feed-top-lead,
body.public-page.feed-insta-ui .ig-feed-top-lead,
body.feed-insta-ui .ig-feed-top-actions,
body.feed-page.feed-insta-ui .ig-feed-top-actions,
body.public-page.feed-insta-ui .ig-feed-top-actions{
  display:flex !important;
  align-items:center !important;
  gap:10px !important;
}
body.feed-insta-ui .ig-feed-top-lead,
body.feed-page.feed-insta-ui .ig-feed-top-lead,
body.public-page.feed-insta-ui .ig-feed-top-lead{
  margin-bottom:var(--msb-feed-user-name-menu-gap, 14px) !important;
  max-width:min(72vw, 520px) !important;
}
body.feed-insta-ui .ig-stories-menu-btn,
body.feed-page.feed-insta-ui .ig-stories-menu-btn,
body.public-page.feed-insta-ui .ig-stories-menu-btn{
  display:inline-flex !important;
  align-items:center !important;
  justify-content:center !important;
  width:var(--msb-feed-chrome-size) !important;
  height:var(--msb-feed-chrome-size) !important;
  min-width:var(--msb-feed-chrome-size) !important;
  min-height:var(--msb-feed-chrome-size) !important;
  padding:0 !important;
  border-radius:var(--msb-feed-chrome-control) !important;
  font-size:var(--msb-feed-chrome-lead-font) !important;
  line-height:1 !important;
  flex-shrink:0 !important;
}
body.feed-insta-ui .ig-stories-menu-btn .fa,
body.feed-insta-ui .ig-stories-menu-btn .icon,
body.feed-page.feed-insta-ui .ig-stories-menu-btn .fa,
body.feed-page.feed-insta-ui .ig-stories-menu-btn .icon,
body.public-page.feed-insta-ui .ig-stories-menu-btn .fa,
body.public-page.feed-insta-ui .ig-stories-menu-btn .icon{
  font-size:var(--msb-feed-chrome-lead-font) !important;
  line-height:1 !important;
  width:auto !important;
  height:auto !important;
}
body.feed-insta-ui .ig-stories-brand,
body.feed-page.feed-insta-ui .ig-stories-brand,
body.public-page.feed-insta-ui .ig-stories-brand{
  display:inline-flex !important;
  align-items:center !important;
  height:var(--msb-feed-chrome-size) !important;
  min-height:var(--msb-feed-chrome-size) !important;
  font-size:var(--msb-feed-chrome-lead-font) !important;
  line-height:1 !important;
  font-weight:800 !important;
  letter-spacing:-.02em !important;
  white-space:nowrap !important;
  flex-shrink:0 !important;
}
body.feed-insta-ui .ig-feed-user-name,
body.feed-page.feed-insta-ui .ig-feed-user-name,
body.public-page.feed-insta-ui .ig-feed-user-name{
  display:inline-flex !important;
  align-items:center !important;
  max-width:min(52vw, 320px) !important;
  height:auto !important;
  min-height:var(--msb-top-action-h) !important;
  font-size:var(--msb-feed-user-name-font, 30px) !important;
  font-family:var(--msb-feed-user-name-font-family, 'Segoe Script', 'Apple Chancery', 'Bradley Hand', cursive) !important;
  line-height:1 !important;
  font-weight:400 !important;
  letter-spacing:.01em !important;
  white-space:nowrap !important;
  overflow:hidden !important;
  text-overflow:ellipsis !important;
  flex-shrink:1 !important;
  color:var(--msb-palette-text-on-nav, var(--msb-palette-text, var(--feed-text, var(--public-text, #0f172a)))) !important;
  text-decoration:none !important;
}
@media (max-width:767px){
  body.feed-insta-ui .ig-feed-user-name,
  body.feed-page.feed-insta-ui .ig-feed-user-name,
  body.public-page.feed-insta-ui .ig-feed-user-name{
    font-size:clamp(22px, 6vw, 28px) !important;
    max-width:min(72vw, 280px) !important;
  }
}
body.feed-insta-ui .ig-feed-account-badge,
body.feed-page.feed-insta-ui .ig-feed-account-badge,
body.public-page.feed-insta-ui .ig-feed-account-badge{
  display:inline-flex !important;
  align-items:center !important;
  max-width:min(22vw, 160px) !important;
  min-height:var(--msb-top-action-h, 32px) !important;
  padding:0 10px !important;
  border-radius:999px !important;
  font-size:12px !important;
  font-weight:700 !important;
  white-space:nowrap !important;
  overflow:hidden !important;
  text-overflow:ellipsis !important;
  flex-shrink:1 !important;
  margin-left: 30px;
}
body.feed-insta-ui .ig-feed-user-name:hover,
body.feed-insta-ui .ig-feed-user-name:focus,
body.feed-page.feed-insta-ui .ig-feed-user-name:hover,
body.feed-page.feed-insta-ui .ig-feed-user-name:focus,
body.public-page.feed-insta-ui .ig-feed-user-name:hover,
body.public-page.feed-insta-ui .ig-feed-user-name:focus{
  color:var(--msb-palette-link, var(--feed-accent, var(--public-accent, #2563eb))) !important;
  text-decoration:none !important;
}
@media (max-width:767px){
  body.feed-insta-ui .ig-stories-menu-btn,
  body.feed-page.feed-insta-ui .ig-stories-menu-btn,
  body.public-page.feed-insta-ui .ig-stories-menu-btn,
  body.feed-insta-ui .ig-stories-menu-btn .fa,
  body.feed-page.feed-insta-ui .ig-stories-menu-btn .fa,
  body.public-page.feed-insta-ui .ig-stories-menu-btn .fa,
  body.feed-insta-ui .ig-stories-brand,
  body.feed-page.feed-insta-ui .ig-stories-brand,
  body.public-page.feed-insta-ui .ig-stories-brand{
    font-size:var(--msb-feed-chrome-lead-font) !important;
  }
}
body.feed-insta-ui .ig-top-mic,
body.feed-insta-ui .ig-top-shop,
body.feed-insta-ui .ig-top-cart,
body.feed-insta-ui .ig-top-more,
body.feed-page.feed-insta-ui .ig-top-mic,
body.feed-page.feed-insta-ui .ig-top-shop,
body.feed-page.feed-insta-ui .ig-top-cart,
body.feed-page.feed-insta-ui .ig-top-more,
body.public-page.feed-insta-ui .ig-top-mic,
body.public-page.feed-insta-ui .ig-top-shop,
body.public-page.feed-insta-ui .ig-top-cart,
body.public-page.feed-insta-ui .ig-top-more{
  width:var(--msb-top-action-h) !important;
  height:var(--msb-top-action-h) !important;
  min-width:var(--msb-top-action-h) !important;
  min-height:var(--msb-top-action-h) !important;
  padding:0 !important;
  border-radius:var(--msb-feed-chrome-circle) !important;
  font-size:18px !important;
}
body.feed-insta-ui .ig-top-live,
body.feed-page.feed-insta-ui .ig-top-live,
body.public-page.feed-insta-ui .ig-top-live{
  min-width:var(--msb-top-action-h) !important;
  height:var(--msb-top-action-h) !important;
  min-height:var(--msb-top-action-h) !important;
  padding:0 18px !important;
  border-radius:var(--msb-feed-chrome-pill) !important;
  font-size:15px !important;
  font-weight:800 !important;
  gap:8px !important;
}
body.feed-insta-ui .ig-top-live i,
body.feed-page.feed-insta-ui .ig-top-live i,
body.public-page.feed-insta-ui .ig-top-live i{
  font-size:16px !important;
}
body.feed-insta-ui .ig-top-live span,
body.feed-page.feed-insta-ui .ig-top-live span,
body.public-page.feed-insta-ui .ig-top-live span{
  font-size:15px !important;
  line-height:1 !important;
}
body.feed-insta-ui .ig-stories-next,
body.public-page.feed-insta-ui .ig-stories-next{
  width:var(--msb-feed-chrome-size) !important;
  height:var(--msb-feed-chrome-size) !important;
  min-width:var(--msb-feed-chrome-size) !important;
  min-height:var(--msb-feed-chrome-size) !important;
  margin-top:0 !important;
  border-radius:var(--msb-feed-chrome-circle) !important;
  font-size:var(--msb-feed-chrome-icon) !important;
}

/* Stories in top header — public.php sizes */
body.feed-insta-ui .ig-feed-header .ig-story-item,
body.feed-page.feed-insta-ui .ig-feed-header .ig-story-item,
body.public-page.feed-insta-ui .ig-feed-header .ig-story-item{
  width:var(--msb-top-story-item) !important;
  min-width:var(--msb-top-story-item) !important;
}
body.feed-insta-ui .ig-feed-header .ig-story-ring,
body.feed-page.feed-insta-ui .ig-feed-header .ig-story-ring,
body.public-page.feed-insta-ui .ig-feed-header .ig-story-ring{
  width:var(--msb-top-story-ring) !important;
  height:var(--msb-top-story-ring) !important;
  margin:0 auto 6px !important;
  padding:2px !important;
}
body.feed-insta-ui .ig-feed-header .ig-story-ring-create,
body.feed-insta-ui .ig-feed-header .ig-story-ring-empty,
body.feed-page.feed-insta-ui .ig-feed-header .ig-story-ring-create,
body.feed-page.feed-insta-ui .ig-feed-header .ig-story-ring-empty,
body.public-page.feed-insta-ui .ig-feed-header .ig-story-ring-create,
body.public-page.feed-insta-ui .ig-feed-header .ig-story-ring-empty{
  width:var(--msb-top-story-ring) !important;
  height:var(--msb-top-story-ring) !important;
  margin:0 auto 6px !important;
}
body.feed-insta-ui .ig-feed-header .ig-story-name,
body.feed-page.feed-insta-ui .ig-feed-header .ig-story-name,
body.public-page.feed-insta-ui .ig-feed-header .ig-story-name{
  max-width:var(--msb-top-story-item) !important;
  font-size:var(--msb-top-story-name-size) !important;
  line-height:1.2 !important;
}
body.feed-insta-ui .ig-feed-header .ig-story-empty,
body.feed-page.feed-insta-ui .ig-feed-header .ig-story-empty,
body.public-page.feed-insta-ui .ig-feed-header .ig-story-empty{
  min-width:var(--msb-top-story-item) !important;
  max-width:var(--msb-top-story-item) !important;
}

/* Stories — compact circles (outside top header) */
body.feed-insta-ui .ig-story-item,
body.public-page.feed-insta-ui .ig-story-item{
  width:var(--msb-feed-chrome-size) !important;
  min-width:var(--msb-feed-chrome-size) !important;
}
body.feed-insta-ui .ig-feed-header .ig-story-item,
body.feed-page.feed-insta-ui .ig-feed-header .ig-story-item,
body.public-page.feed-insta-ui .ig-feed-header .ig-story-item{
  width:var(--msb-top-story-item) !important;
  min-width:var(--msb-top-story-item) !important;
}
body.feed-insta-ui .ig-story-ring,
body.public-page.feed-insta-ui .ig-story-ring{
  width:var(--msb-feed-chrome-size) !important;
  height:var(--msb-feed-chrome-size) !important;
  margin:0 auto 4px !important;
  padding:2px !important;
  border-radius:var(--msb-feed-chrome-circle) !important;
  background:linear-gradient(45deg,#f58529,#dd2a7b,#8134af,#515bd4) !important;
  border:0 !important;
  box-sizing:border-box !important;
}
body.feed-insta-ui .ig-feed-header .ig-story-ring,
body.feed-page.feed-insta-ui .ig-feed-header .ig-story-ring,
body.public-page.feed-insta-ui .ig-feed-header .ig-story-ring{
  width:var(--msb-top-story-ring) !important;
  height:var(--msb-top-story-ring) !important;
  margin:0 auto 6px !important;
}
body.feed-insta-ui .ig-story-ring-create,
body.feed-insta-ui .ig-story-ring-empty,
body.public-page.feed-insta-ui .ig-story-ring-create,
body.public-page.feed-insta-ui .ig-story-ring-empty{
  width:var(--msb-feed-chrome-size) !important;
  height:var(--msb-feed-chrome-size) !important;
  margin:0 auto 4px !important;
  padding:0 !important;
  border-radius:var(--msb-feed-chrome-circle) !important;
  box-sizing:border-box !important;
}
body.feed-insta-ui .ig-story-ring img,
body.feed-insta-ui .ig-story-thumb,
body.public-page.feed-insta-ui .ig-story-ring img,
body.public-page.feed-insta-ui .ig-story-thumb{
  width:100% !important;
  height:100% !important;
  border-radius:var(--msb-feed-chrome-circle) !important;
  border:2px solid var(--msb-palette-bg, #fff) !important;
  object-fit:cover !important;
  box-sizing:border-box !important;
}
body.feed-insta-ui .ig-story-create .ig-story-ring-create,
body.public-page.feed-insta-ui .ig-story-create .ig-story-ring-create{
  background:var(--msb-palette-bg, #fafafa) !important;
  border:2px solid var(--msb-palette-border-strong, #dbdbdb) !important;
  display:flex !important;
  align-items:center !important;
  justify-content:center !important;
}
body.feed-insta-ui .ig-story-create .ig-story-ring-create i,
body.public-page.feed-insta-ui .ig-story-create .ig-story-ring-create i{
  font-size:var(--msb-feed-chrome-icon) !important;
}
body.feed-insta-ui .ig-story-ring-empty,
body.public-page.feed-insta-ui .ig-story-ring-empty{
  background:#e4e7ec !important;
  border:0 !important;
}
body.feed-insta-ui .ig-story-empty-icon,
body.public-page.feed-insta-ui .ig-story-empty-icon{
  width:100% !important;
  height:100% !important;
  border-radius:var(--msb-feed-chrome-circle) !important;
  border:2px solid var(--msb-palette-bg, #fff) !important;
  background:var(--msb-palette-bg, #f2f4f7) !important;
  font-size:var(--msb-feed-chrome-icon) !important;
  box-sizing:border-box !important;
}
body.feed-insta-ui .ig-story-name,
body.public-page.feed-insta-ui .ig-story-name{
  max-width:var(--msb-feed-chrome-size) !important;
  font-size:10px !important;
  line-height:1.1 !important;
  font-weight:600 !important;
}
body.feed-insta-ui .ig-story-empty,
body.public-page.feed-insta-ui .ig-story-empty{
  min-width:var(--msb-feed-chrome-size) !important;
  max-width:var(--msb-feed-chrome-size) !important;
}
body.feed-insta-ui .ig-story-create:focus-visible,
body.public-page.feed-insta-ui .ig-story-create:focus-visible{
  border-radius:var(--msb-feed-chrome-circle) !important;
}

/* Search row — public.php height */
body.feed-insta-ui .feed-top-search,
body.feed-page.feed-insta-ui .feed-top-search,
body.public-page.feed-insta-ui .feed-top-search{
  padding:var(--msb-top-search-pad-top) var(--msb-top-search-pad-x) var(--msb-top-search-pad-bottom) !important;
  box-sizing:border-box !important;
}
body.feed-insta-ui .feed-top-search-field,
body.feed-page.feed-insta-ui .feed-top-search-field,
body.public-page.feed-insta-ui .feed-top-search-field,
body.public-page.feed-insta-ui .public-publisher-search{
  position:relative !important;
  width:100% !important;
}
body.feed-insta-ui .feed-top-search-input,
body.feed-page.feed-insta-ui .feed-top-search-input,
body.public-page.feed-insta-ui .feed-top-search-input,
body.public-page.feed-insta-ui .public-publisher-search input{
  height:var(--msb-top-search-input-h) !important;
  min-height:var(--msb-top-search-input-h) !important;
  border-radius:var(--msb-feed-chrome-pill) !important;
  font-size:var(--msb-feed-chrome-font) !important;
  padding-right:44px !important;
  box-sizing:border-box !important;
}
body.feed-insta-ui .feed-top-search-icon,
body.public-page.feed-insta-ui .feed-top-search-icon,
body.public-page.feed-insta-ui .public-publisher-search button{
  width:calc(var(--msb-feed-chrome-size) - 8px) !important;
  height:calc(var(--msb-feed-chrome-size) - 8px) !important;
  border-radius:var(--msb-feed-chrome-circle) !important;
  color:var(--msb-palette-action, var(--feed-accent, var(--public-accent, #2563eb))) !important;
  background:transparent !important;
}
body.feed-insta-ui .feed-top-search-icon:hover,
body.feed-insta-ui .feed-top-search-icon:focus,
body.public-page.feed-insta-ui .feed-top-search-icon:hover,
body.public-page.feed-insta-ui .feed-top-search-icon:focus,
body.public-page.feed-insta-ui .public-publisher-search button:hover{
  background:var(--msb-palette-action-soft, var(--feed-accent-soft, var(--public-accent-soft, rgba(37,99,235,.12)))) !important;
  filter:none !important;
}

/* Desktop — pin header + search (public.php scroll shell) */
@media (min-width:1025px){
  body.feed-insta-ui .sh-pagebody > .ig-feed-header,
  body.feed-page.feed-insta-ui .sh-pagebody > .ig-feed-header,
  body.public-page.feed-insta-ui .sh-pagebody > .ig-feed-header{
    flex:0 0 auto !important;
    position:relative !important;
    top:auto !important;
    z-index:110 !important;
    margin:0 !important;
    padding:var(--msb-top-header-pad-top) 0 var(--msb-top-header-pad-bottom) !important;
    align-items:flex-start !important;
    justify-content:center !important;
  }
  body.feed-insta-ui .sh-pagebody > .feed-top-search,
  body.feed-page.feed-insta-ui .sh-pagebody > .feed-top-search,
  body.public-page.feed-insta-ui .sh-pagebody > .feed-top-search{
    flex:0 0 auto !important;
    position:relative !important;
    top:auto !important;
    z-index:105 !important;
    padding:var(--msb-top-search-pad-top) var(--msb-top-search-pad-x) var(--msb-top-search-pad-bottom) !important;
  }
  body.feed-insta-ui .ig-stories-wrap,
  body.feed-page.feed-insta-ui .ig-stories-wrap,
  body.public-page.feed-insta-ui .ig-stories-wrap{
    display:block !important;
    max-width:614px !important;
    width:100% !important;
    margin:0 auto !important;
  }
  body.feed-insta-ui .ig-feed-header,
  body.feed-page.feed-insta-ui .ig-feed-header,
  body.public-page.feed-insta-ui .ig-feed-header{
    display:flex !important;
    padding-left:0 !important;
    padding-right:0 !important;
  }
  body.feed-insta-ui .ig-feed-top-lead,
  body.feed-page.feed-insta-ui .ig-feed-top-lead,
  body.public-page.feed-insta-ui .ig-feed-top-lead{
    left:16px !important;
    display:flex !important;
  }
  body.feed-insta-ui .ig-feed-top-actions,
  body.feed-page.feed-insta-ui .ig-feed-top-actions,
  body.public-page.feed-insta-ui .ig-feed-top-actions{
    right:16px !important;
  }
}

/* Side rails */
@media (min-width:1025px){
  body.feed-insta-ui .feed-left-nav-item,
  body.feed-insta-ui .feed-right-nav-item,
  body.public-page.feed-insta-ui .feed-left-nav-item,
  body.public-page.feed-insta-ui .feed-right-nav-item{
    min-height:var(--msb-feed-chrome-size) !important;
    border-radius:var(--msb-feed-chrome-control) !important;
    font-size:var(--msb-feed-chrome-font) !important;
  }
  body.feed-insta-ui .feed-left-nav-ic,
  body.feed-insta-ui .feed-right-nav-ic,
  body.public-page.feed-insta-ui .feed-left-nav-ic,
  body.public-page.feed-insta-ui .feed-right-nav-ic{
    width:var(--msb-feed-chrome-icon) !important;
    height:var(--msb-feed-chrome-icon) !important;
    flex:0 0 var(--msb-feed-chrome-icon) !important;
  }
  body.feed-insta-ui .feed-left-nav-ic svg,
  body.feed-insta-ui .feed-right-nav-ic svg,
  body.public-page.feed-insta-ui .feed-left-nav-ic svg,
  body.public-page.feed-insta-ui .feed-right-nav-ic svg{
    width:var(--msb-feed-chrome-icon) !important;
    height:var(--msb-feed-chrome-icon) !important;
    max-width:var(--msb-feed-chrome-icon) !important;
    max-height:var(--msb-feed-chrome-icon) !important;
  }
  body.feed-insta-ui .feed-right-nav-badge,
  body.public-page.feed-insta-ui .feed-right-nav-badge,
  body.feed-insta-ui .feed-left-nav-badge,
  body.public-page.feed-insta-ui .feed-left-nav-badge{
    border-radius:var(--msb-feed-chrome-pill) !important;
    font-size:10px !important;
  }
}
