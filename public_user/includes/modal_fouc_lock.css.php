<?php
declare(strict_types=1);
/**
 * Early hide for closed modals/dialogs so they cannot flash on refresh.
 * Bootstrap/shamcey reboot sets dialog{display:block}, and #pvOverlay CSS
 * historically lived after the HTML — both caused FOUC of share/mention
 * sheets and post-viewer chrome.
 */
?>
/* Closed native dialogs (share / tag / mention / confirm) */
dialog:not([open]),
dialog.pcm-share-dialog:not([open]),
dialog.pcm-delete-dialog:not([open]),
dialog.pcm-tag-dialog:not([open]),
dialog.pcm-mention-dialog:not([open]),
dialog.pcm-archive-dialog:not([open]),
dialog.feed-delete-dialog:not([open]){
  display:none !important;
  visibility:hidden !important;
  pointer-events:none !important;
}
/* Post viewer overlay — hidden until .show */
#pvOverlay.pv-overlay:not(.show),
.pv-overlay:not(.show){
  display:none !important;
  visibility:hidden !important;
  opacity:0 !important;
  pointer-events:none !important;
}
#pvOverlay.pv-overlay.show,
.pv-overlay.show{
  display:flex !important;
  visibility:visible !important;
  opacity:1 !important;
  pointer-events:auto !important;
}
/* View-the-post iframe overlay */
.pcm-view-post-overlay:not(.is-open),
#pcmViewPostOverlay:not(.is-open){
  display:none !important;
  visibility:hidden !important;
  pointer-events:none !important;
}
/* Legacy selected-post viewer (not used by Friends For You card feed) */
body.feed-page.feed-insta-ui .row.row-sm.desktop-only,
body.feed-page.feed-insta-ui #feedPostScrollCol,
body.feed-page.feed-insta-ui #feedPostScrollCol .ig-post-shell,
body.feed-page.feed-insta-ui #feedPostScrollCol .ig-post-card{
  display:none !important;
  visibility:hidden !important;
  opacity:0 !important;
  pointer-events:none !important;
}
