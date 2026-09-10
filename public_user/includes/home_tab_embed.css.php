<?php
declare(strict_types=1);
/**
 * Tab-switch overlay: keep inner page layout identical to a full refresh
 * (so card media queries match). Only hide duplicate chrome from clicks.
 * Parent clips the iframe to the 614px column.
 */
?>
html.tab-embed .feed-ig-rail,
html.tab-embed .feed-left-rail,
html.tab-embed .feed-left-rail-page-head,
html.tab-embed .ig-feed-header,
html.tab-embed .feed-side-search,
html.tab-embed .feed-top-search,
html.tab-embed .feed-right-rail,
html.tab-embed #ttNavLeftbar,
html.tab-embed .sh-sideleft-menu,
html.tab-embed .lb-overlay,
html.tab-embed body.feed-page.feed-insta-ui .feed-side-search,
html.tab-embed body.public-page.feed-insta-ui .feed-side-search,
html.tab-embed body.feed-page.feed-insta-ui .feed-top-search,
html.tab-embed body.public-page.feed-insta-ui .feed-top-search,
html.tab-embed body.feed-insta-ui .feed-top-search--tabs-only{
  visibility:hidden !important;
  pointer-events:none !important;
}
.home-tab-frame-host{
  flex:1 1 auto;
  min-height:0;
  width:100%;
  overflow:hidden;
  position:relative;
}
body.feed-insta-ui .feed-desktop-center > .home-tab-frame-host{
  flex:1 1 auto !important;
  min-height:0 !important;
  width:100% !important;
  overflow:hidden !important;
  position:relative !important;
}
body.feed-page.feed-insta-ui .feed-desktop-center > .mf-feed[hidden],
body.public-page.feed-insta-ui .feed-desktop-center > .ig-feed[hidden],
body.feed-insta-ui .feed-desktop-center > .home-tab-frame-host[hidden]{
  display:none !important;
}
.home-tab-frame{
  position:absolute;
  left:0;
  top:0;
  border:0;
  display:block;
  background:transparent;
}
