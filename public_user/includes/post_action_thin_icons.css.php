<?php
declare(strict_types=1);
?>
/* Thin post-action icons (X-style line icons) */
:root,
html[data-msb-appearance],
html.dark-auto,
html[data-theme="dark"]{
  /* Fixed reaction colors — same in picker + action bar; never follow appearance/progress/dark-auto */
  --msb-rx-like:#2563eb !important;
  --msb-rx-love:#ff4d6d !important;
  --msb-rx-dislike:#475569 !important;
  --msb-love-color:#ff4d6d !important;
  /* White halo + dark edge so icons/counts read on teal / dark / media backgrounds */
  --msb-pact-contrast-filter:drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 0 .6px rgba(255,255,255,.95)) drop-shadow(0 1px 2px rgba(0,0,0,.55));
  --msb-pact-contrast-text-shadow:0 0 2px rgba(255,255,255,.95), 0 0 1px rgba(255,255,255,.9), 0 1px 2px rgba(0,0,0,.5);
}
.msb-pact{
  display:inline-block;
  width:1.375em;
  height:1.375em;
  min-width:1.375em;
  min-height:1.375em;
  flex:0 0 1.375em;
  vertical-align:-0.2em;
  background:currentColor;
  text-shadow:none !important;
  font-style:normal;
  line-height:1;
  -webkit-mask:var(--msb-pact-mask) center / contain no-repeat;
  mask:var(--msb-pact-mask) center / contain no-repeat;
  filter:var(--msb-pact-contrast-filter);
}
.msb-pact-heart{
  --msb-pact-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='1.85' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z'/%3E%3C/svg%3E");
}
.msb-pact-heart.is-active,
.is-love .msb-pact-heart{
  --msb-pact-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23000'%3E%3Cpath d='M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z'/%3E%3C/svg%3E");
}
.msb-pact-thumb{
  --msb-pact-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='1.85' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3H14z'/%3E%3Cpath d='M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3'/%3E%3C/svg%3E");
}
.msb-pact-thumb.is-active,
.is-like .msb-pact-thumb{
  --msb-pact-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23000'%3E%3Cpath d='M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3H14zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3'/%3E%3C/svg%3E");
  color:var(--msb-rx-like, #2563eb) !important;
}
.msb-pact-thumb-down{
  --msb-pact-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='1.85' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3H10'/%3E%3Cpath d='M17 2h3a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-3'/%3E%3C/svg%3E");
}
.msb-pact-thumb-down.is-active{
  --msb-pact-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23000'%3E%3Cpath d='M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3H10zm7-13h3a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-3'/%3E%3C/svg%3E");
  color:var(--msb-rx-dislike, #475569) !important;
}
.msb-pact-comment{
  --msb-pact-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='1.85' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z'/%3E%3C/svg%3E");
}
.msb-pact-share{
  --msb-pact-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='1.85' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8'/%3E%3Cpolyline points='16 6 12 2 8 6'/%3E%3Cline x1='12' y1='2' x2='12' y2='15'/%3E%3C/svg%3E");
}
.msb-pact-bookmark{
  --msb-pact-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='1.85' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z'/%3E%3C/svg%3E");
}
.msb-pact-bookmark.is-active,
.is-save .msb-pact-bookmark{
  --msb-pact-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23000'%3E%3Cpath d='M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z'/%3E%3C/svg%3E");
}
.msb-reaction-glyph{
  display:inline-flex !important;
  align-items:center;
  justify-content:center;
  width:16px;
  height:16px;
  min-width:16px;
  min-height:16px;
  flex:0 0 16px;
  line-height:1 !important;
  font-style:normal !important;
  font-size:16px !important;
  background:transparent !important;
  -webkit-mask:none !important;
  mask:none !important;
  text-shadow:none;
  filter:var(--msb-pact-contrast-filter);
}
.post.public-post-card .standard-media-btn .msb-pact,
.post.public-post-card .standard-text-btn .msb-pact,
.post.public-post-card .reel-inline-btn .msb-pact,
.post.public-post-card .public-live-action-btn .msb-pact,
.post.public-post-card .action-btn .msb-pact,
.mf-feed .mf-act .msb-pact,
body.profile-page #profilePostsFeed .mf-act .msb-pact,
.pv-act .msb-pact,
.has-rx-icon .msb-pact-heart,
.is-love .msb-pact-heart,
.msb-pact-heart.is-active,
.has-rx-icon .msb-pact-thumb,
.is-like .msb-pact-thumb,
.msb-pact-thumb.is-active,
.has-rx-icon .msb-pact-thumb-down,
.msb-pact-thumb-down.is-active,
body.reel-page .reel-act .msb-pact,
body.reel-page .reel-act .msb-reaction-glyph{
  width:16px !important;
  height:16px !important;
  min-width:16px !important;
  min-height:16px !important;
  flex-basis:16px !important;
}
.post.public-post-card .standard-media-btn .msb-pact{
  color:#fff;
}
.post.public-post-card:not(.is-reel-post) .standard-text-btn .msb-pact,
.post.public-post-card:not(.is-reel-post) .action-btn .msb-pact{
  color:var(--public-text, #132033);
}
.post.public-post-card .standard-media-btn.is-love .msb-pact-heart,
.post.public-post-card .standard-text-btn.is-love .msb-pact-heart,
.post.public-post-card .public-live-action-btn.is-love .msb-pact-heart,
.post.public-post-card .reel-inline-btn.is-love .msb-pact-heart,
.mf-feed .mf-act.is-love .msb-pact-heart{
  color:var(--msb-love-color) !important;
}
.post.public-post-card .standard-media-btn.is-share .msb-pact-share,
.post.public-post-card .standard-text-btn.is-share .msb-pact-share,
.post.public-post-card .public-live-action-btn.is-share .msb-pact-share,
.post.public-post-card .reel-inline-btn.is-share .msb-pact-share,
.mf-feed .mf-act.is-share .msb-pact-share{
  color:#6b7280 !important;
}
.post.public-post-card .standard-media-btn.is-save .msb-pact-bookmark,
.post.public-post-card .standard-text-btn.is-save .msb-pact-bookmark,
.post.public-post-card .public-live-action-btn.is-save .msb-pact-bookmark,
.post.public-post-card .reel-inline-btn.is-save .msb-pact-bookmark,
.mf-feed .mf-act.is-save .msb-pact-bookmark{
  color:#f59e0b !important;
}
.mf-feed .mf-act.is-love,
.mf-feed .mf-act.is-love i,
.mf-feed .mf-act.is-love .mf-num,
.ig-act.is-love i,
.vrail-btn.is-love i,
.pv-act.is-love,
.pv-act.is-love i,
.ig-profile-love-btn.is-loved,
.ig-profile-love-btn.is-loved i,
.tt-stories-lovebtn.is-loved,
.tt-stories-lovebtn.liked,
.tt-stories-lovebtn.rx-active,
.tt-stories-action.is-loved,
.tt-stories-action.is-loved i,
.tt-stories-action.is-loved .tt-stories-action-count,
.tt-stories-action.liked,
.tt-stories-action.liked i,
.tt-stories-action.rx-active,
.tt-stories-action.rx-active i,
#tt-stories-wrap .tt-stories-action.is-loved,
#tt-stories-wrap .tt-stories-action.is-loved i,
#tt-stories-wrap .tt-stories-action.is-loved .tt-stories-action-count,
#tt-stories-wrap .tt-stories-action.liked,
#tt-stories-wrap .tt-stories-action.liked i,
#tt-stories-wrap .tt-stories-action.rx-active,
#tt-stories-wrap .tt-stories-action.rx-active i,
#tt-stories-wrap .tt-stories-action.rx-active .tt-stories-action-count,
.post.public-post-card .standard-text-btn.is-love i,
.post.public-post-card .standard-media-btn.is-love i,
.post.public-post-card .public-live-action-btn.is-love i,
.post.public-post-card .reel-inline-btn.is-love i,
.post.public-post-card .action-btn.is-love i,
body.profile-page #profilePostsFeed .mf-act.is-love,
body.profile-page #profilePostsFeed .mf-act.is-love i,
body.profile-page #profilePostsFeed .mf-act.is-love .mf-num{
  color:var(--msb-love-color) !important;
}

/* Keep love/comment/share/save readable on Appearance page colors + media */
.mf-feed .mf-act .msb-pact,
body.profile-page #profilePostsFeed .mf-act .msb-pact,
.post.public-post-card .standard-text-btn .msb-pact,
.post.public-post-card .standard-media-btn .msb-pact,
.post.public-post-card .action-btn .msb-pact,
.post.public-post-card .reel-inline-btn .msb-pact,
.post.public-post-card .public-live-action-btn .msb-pact,
body.reel-page .reel-act .msb-pact{
  filter:var(--msb-pact-contrast-filter) !important;
}
.mf-feed .mf-act .mf-num,
body.profile-page #profilePostsFeed .mf-act .mf-num,
.post.public-post-card .standard-text-btn .action-count,
.post.public-post-card .standard-media-btn .action-count,
.post.public-post-card .action-btn .action-count,
.post.public-post-card .reel-inline-btn .action-count,
.post.public-post-card .public-live-action-btn .action-count,
.post.public-post-card .msb-react-cluster .action-count,
body.reel-page .reel-act-count{
  text-shadow:var(--msb-pact-contrast-text-shadow) !important;
}
html[data-msb-appearance] .mf-feed .mf-act:not(.is-love):not(.is-like):not(.is-save):not(.is-share):not(.is-reacted),
html[data-msb-appearance] .mf-feed .mf-act:not(.is-love):not(.is-like):not(.is-save):not(.is-share):not(.is-reacted) .msb-pact,
html[data-msb-appearance] .mf-feed .mf-act:not(.is-love):not(.is-like):not(.is-save):not(.is-share):not(.is-reacted) .mf-num,
html[data-msb-appearance] body.profile-page #profilePostsFeed .mf-act:not(.is-love):not(.is-like):not(.is-save):not(.is-share):not(.is-reacted),
html[data-msb-appearance] body.profile-page #profilePostsFeed .mf-act:not(.is-love):not(.is-like):not(.is-save):not(.is-share):not(.is-reacted) .msb-pact,
html[data-msb-appearance] body.profile-page #profilePostsFeed .mf-act:not(.is-love):not(.is-like):not(.is-save):not(.is-share):not(.is-reacted) .mf-num,
html[data-msb-appearance] .post.public-post-card:not(.is-reel-post) .standard-text-btn:not(.is-love):not(.is-like):not(.is-save):not(.is-share):not(.is-reacted),
html[data-msb-appearance] .post.public-post-card:not(.is-reel-post) .standard-text-btn:not(.is-love):not(.is-like):not(.is-save):not(.is-share):not(.is-reacted) .msb-pact,
html[data-msb-appearance] .post.public-post-card:not(.is-reel-post) .standard-text-btn:not(.is-love):not(.is-like):not(.is-save):not(.is-share):not(.is-reacted) .action-count,
html[data-msb-appearance] .post.public-post-card:not(.is-reel-post) .msb-react-cluster .action-count,
html[data-msb-appearance] .post.public-post-card:not(.is-reel-post) .action-btn:not(.is-love):not(.is-like):not(.is-save):not(.is-share):not(.is-reacted),
html[data-msb-appearance] .post.public-post-card:not(.is-reel-post) .action-btn:not(.is-love):not(.is-like):not(.is-save):not(.is-share):not(.is-reacted) .msb-pact{
  color:var(--msb-palette-text, #0f172a) !important;
  -webkit-text-fill-color:var(--msb-palette-text, #0f172a) !important;
}

/* Locked reaction icon colors (picker + selected action) beat appearance / progress / dark-auto */
html[data-msb-appearance] .has-rx-icon[data-selected-reaction="like"] .msb-pact-thumb,
html[data-msb-appearance] .is-like .msb-pact-thumb,
html[data-msb-appearance] .msb-pact-thumb.is-active,
html.dark-auto .has-rx-icon[data-selected-reaction="like"] .msb-pact-thumb,
html.dark-auto .is-like .msb-pact-thumb,
html[data-theme="dark"] .has-rx-icon[data-selected-reaction="like"] .msb-pact-thumb,
html[data-theme="dark"] .is-like .msb-pact-thumb,
html[data-msb-appearance] .msb-reaction-picker-item[data-reaction="like"] .msb-reaction-picker-emoji,
html.dark-auto .msb-reaction-picker-item[data-reaction="like"] .msb-reaction-picker-emoji,
html[data-theme="dark"] .msb-reaction-picker-item[data-reaction="like"] .msb-reaction-picker-emoji{
  color:var(--msb-rx-like, #2563eb) !important;
  -webkit-text-fill-color:var(--msb-rx-like, #2563eb) !important;
  fill:var(--msb-rx-like, #2563eb) !important;
}
html[data-msb-appearance] .has-rx-icon[data-selected-reaction="love"] .msb-pact-heart,
html[data-msb-appearance] .is-love .msb-pact-heart,
html[data-msb-appearance] .msb-pact-heart.is-active,
html.dark-auto .has-rx-icon[data-selected-reaction="love"] .msb-pact-heart,
html.dark-auto .is-love .msb-pact-heart,
html[data-theme="dark"] .has-rx-icon[data-selected-reaction="love"] .msb-pact-heart,
html[data-theme="dark"] .is-love .msb-pact-heart,
html[data-msb-appearance] .msb-reaction-picker-item[data-reaction="love"] .msb-reaction-picker-emoji,
html.dark-auto .msb-reaction-picker-item[data-reaction="love"] .msb-reaction-picker-emoji,
html[data-theme="dark"] .msb-reaction-picker-item[data-reaction="love"] .msb-reaction-picker-emoji{
  color:var(--msb-rx-love, #ff4d6d) !important;
  -webkit-text-fill-color:var(--msb-rx-love, #ff4d6d) !important;
  fill:var(--msb-rx-love, #ff4d6d) !important;
}
html[data-msb-appearance] .has-rx-icon[data-selected-reaction="dislike"] .msb-pact-thumb-down,
html[data-msb-appearance] .msb-pact-thumb-down.is-active,
html.dark-auto .has-rx-icon[data-selected-reaction="dislike"] .msb-pact-thumb-down,
html[data-theme="dark"] .has-rx-icon[data-selected-reaction="dislike"] .msb-pact-thumb-down,
html[data-msb-appearance] .msb-reaction-picker-item[data-reaction="dislike"] .msb-reaction-picker-emoji,
html.dark-auto .msb-reaction-picker-item[data-reaction="dislike"] .msb-reaction-picker-emoji,
html[data-theme="dark"] .msb-reaction-picker-item[data-reaction="dislike"] .msb-reaction-picker-emoji{
  color:var(--msb-rx-dislike, #475569) !important;
  -webkit-text-fill-color:var(--msb-rx-dislike, #475569) !important;
  fill:var(--msb-rx-dislike, #475569) !important;
}
html[data-msb-appearance] .has-rx-icon[data-selected-reaction="smile"] .msb-rx-face,
html[data-msb-appearance] .has-rx-icon[data-selected-reaction="laugh"] .msb-rx-face,
html[data-msb-appearance] .has-rx-icon[data-selected-reaction="wow"] .msb-rx-face,
html[data-msb-appearance] .has-rx-icon[data-selected-reaction="sad"] .msb-rx-face,
html[data-msb-appearance] .has-rx-icon[data-selected-reaction="angry"] .msb-rx-face,
html.dark-auto .has-rx-icon[data-selected-reaction="smile"] .msb-rx-face,
html.dark-auto .has-rx-icon[data-selected-reaction="laugh"] .msb-rx-face,
html.dark-auto .has-rx-icon[data-selected-reaction="wow"] .msb-rx-face,
html.dark-auto .has-rx-icon[data-selected-reaction="sad"] .msb-rx-face,
html.dark-auto .has-rx-icon[data-selected-reaction="angry"] .msb-rx-face,
html[data-theme="dark"] .has-rx-icon[data-selected-reaction="smile"] .msb-rx-face,
html[data-theme="dark"] .has-rx-icon[data-selected-reaction="laugh"] .msb-rx-face,
html[data-theme="dark"] .has-rx-icon[data-selected-reaction="wow"] .msb-rx-face,
html[data-theme="dark"] .has-rx-icon[data-selected-reaction="sad"] .msb-rx-face,
html[data-theme="dark"] .has-rx-icon[data-selected-reaction="angry"] .msb-rx-face{
  color:transparent !important;
  -webkit-text-fill-color:transparent !important;
  background:transparent !important;
  fill:none !important;
}

/* Keep love / thumbs / comment / save / share clickable on every surface.
   Do not unlock the whole media overlay — only the action controls. */
.mf-actions,
.mf-act,
.mf-act .msb-pact,
.mf-act .mf-num,
.standard-media-actions,
.standard-media-btn,
.standard-text-actions,
.standard-text-btn,
.action-btn,
.reel-inline-btn,
.public-live-action-btn,
.reel-act-wrap,
.reel-act,
.pv-act,
.pv-actions,
.js-react-love,
.js-react-like,
.js-save-post,
.js-share-post,
.js-open-comments,
.tt-stories-action,
.tt-stories-lovebtn,
.tt-stories-publisher-foot,
.tt-stories-user-foot,
.tt-stories-input-row{
  pointer-events:auto !important;
}
.mf-act,
.standard-media-btn,
.standard-text-btn,
.action-btn,
.reel-inline-btn,
.public-live-action-btn,
.reel-act,
.pv-act,
.js-react-love,
.js-save-post,
.js-share-post,
.js-open-comments,
.tt-stories-action{
  cursor:pointer;
}
.msb-reaction-picker.is-open{
  z-index:2147483000 !important;
  pointer-events:auto !important;
}
