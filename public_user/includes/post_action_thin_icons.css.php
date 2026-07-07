<?php
declare(strict_types=1);
?>
/* Thin post-action icons (X-style line icons) */
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
}
.msb-pact-heart{
  --msb-pact-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='1.15' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z'/%3E%3C/svg%3E");
}
.msb-pact-heart.is-active,
.is-love .msb-pact-heart{
  --msb-pact-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23000'%3E%3Cpath d='M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z'/%3E%3C/svg%3E");
}
.msb-pact-comment{
  --msb-pact-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='1.15' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z'/%3E%3C/svg%3E");
}
.msb-pact-share{
  --msb-pact-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='1.15' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8'/%3E%3Cpolyline points='16 6 12 2 8 6'/%3E%3Cline x1='12' y1='2' x2='12' y2='15'/%3E%3C/svg%3E");
}
.msb-pact-bookmark{
  --msb-pact-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='1.15' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z'/%3E%3C/svg%3E");
}
.msb-pact-bookmark.is-active,
.is-save .msb-pact-bookmark{
  --msb-pact-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23000'%3E%3Cpath d='M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z'/%3E%3C/svg%3E");
}
.post.public-post-card .standard-media-btn .msb-pact,
.post.public-post-card .standard-text-btn .msb-pact,
.post.public-post-card .reel-inline-btn .msb-pact,
.post.public-post-card .public-live-action-btn .msb-pact,
.post.public-post-card .action-btn .msb-pact,
.mf-feed .mf-act .msb-pact{
  width:22px;
  height:22px;
  min-width:22px;
  min-height:22px;
  flex-basis:22px;
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
  color:#ef2b7b !important;
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
