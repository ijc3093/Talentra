:root{
  --pcm-on-media-circle-size:36px;
}
.post-card-menu-wrap{
  position:relative;
  flex:0 0 auto;
  margin-left:auto;
  z-index:60;
  pointer-events:auto;
}
.post-card-menu-btn{
  width:36px;
  height:36px;
  min-width:36px;
  min-height:36px;
  padding:0;
  border:1px solid rgba(147,197,253,.85);
  border-radius:10px;
  background:rgba(255,255,255,.94);
  color:#5c3d2e !important;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
  line-height:1;
  box-shadow:0 2px 8px rgba(15,23,42,.08);
}
.post-card-menu-btn i{
  color:#5c3d2e !important;
  font-size:16px;
  line-height:1;
  text-shadow:none !important;
}
.post-card-menu-btn:hover,
.post-card-menu-btn:focus{
  background:#fff;
  outline:none;
  box-shadow:0 4px 12px rgba(15,23,42,.12);
}
.post-card-menu{
  position:absolute;
  top:calc(100% + 8px);
  right:0;
  min-width:220px;
  background:var(--pcm-menu-bg, #fff8f3);
  border:1px solid var(--pcm-menu-border, rgba(107,58,30,.08));
  border-radius:20px;
  box-shadow:var(--pcm-menu-shadow, 0 16px 40px rgba(92,61,46,.18));
  padding:10px 8px;
  z-index:120;
  display:none;
}
.post-card-menu.open,
.mf-menu.post-card-menu.open{
  display:block !important;
}
.post-card-menu-wrap.pcm-wrap-open > .post-card-menu,
.mf-menu-wrap.pcm-wrap-open > .post-card-menu,
.post-card-menu-wrap.pcm-wrap-open > .mf-menu.post-card-menu,
.mf-menu-wrap.pcm-wrap-open > .mf-menu.post-card-menu{
  display:none !important;
  visibility:hidden !important;
  pointer-events:none !important;
}
.pcm-menu-portal,
.mf-menu.post-card-menu.pcm-menu-portal,
.post-card-menu.pcm-menu-portal{
  display:block !important;
  position:fixed !important;
  z-index:100000 !important;
  pointer-events:auto !important;
}
.post-card-menu .pcm-item{
  width:100%;
  display:flex;
  align-items:center;
  gap:12px;
  padding:11px 14px;
  border:0;
  background:transparent;
  color:var(--pcm-menu-text, #6b3a1e);
  text-decoration:none;
  font-weight:700;
  font-size:15px;
  line-height:1.2;
  border-radius:12px;
  cursor:pointer;
  text-align:left;
}
.post-card-menu .pcm-item i,
.post-card-menu .pcm-item .icon{
  width:18px;
  min-width:18px;
  text-align:center;
  color:var(--pcm-menu-text, #6b3a1e);
  font-size:16px;
  line-height:1;
}
.post-card-menu .pcm-item:hover,
.post-card-menu .pcm-item:focus{
  background:var(--pcm-menu-hover-bg, rgba(107,58,30,.08));
  outline:none;
  color:var(--pcm-menu-text-hover, #5c2f16);
}
.post-card-menu .pcm-item:hover i,
.post-card-menu .pcm-item:hover .icon,
.post-card-menu .pcm-item:focus i,
.post-card-menu .pcm-item:focus .icon{
  color:var(--pcm-menu-text-hover, #5c2f16);
}
.post-card-menu .pcm-divider{
  height:1px;
  margin:6px 10px;
  background:var(--pcm-menu-divider, rgba(107,58,30,.12));
}
.mf-media-shell > .mf-head--on-media .post-card-menu-btn,
.mf-media-shell > .mf-head--on-media .post-card-menu-btn i,
.standard-media-topbar .post-card-menu-btn,
.standard-media-topbar .post-card-menu-btn i,
.reel-stage .post-card-menu-btn,
.post-card-head-actions .post-card-menu-btn{
  pointer-events:auto;
}
.mf-media-shell > .mf-head--on-media .post-card-menu-wrap,
.mf-media-shell > .mf-head--on-media .mf-menu-wrap.post-card-menu-wrap,
.standard-media-topbar .post-card-menu-wrap,
.post-card-head-actions .post-card-menu-wrap{
  pointer-events:auto !important;
  position:relative;
  z-index:60 !important;
}
.mf-media-shell > .mf-head--on-media .post-card-menu-btn,
.standard-media-topbar .post-card-menu-btn{
  position:relative;
  z-index:61 !important;
  pointer-events:auto !important;
}
html[data-msb-appearance] body .mf-media-shell > .mf-head--on-media .post-card-menu-btn,
html[data-msb-appearance] body .standard-media-topbar .post-card-menu-btn,
html[data-msb-appearance] body.news-page .post.public-post-card .standard-media-topbar .post-card-menu-btn{
  color:#fff !important;
  background:rgba(15,23,42,.52) !important;
  border-color:rgba(255,255,255,.34) !important;
  backdrop-filter:blur(10px) !important;
  -webkit-backdrop-filter:blur(10px) !important;
  box-shadow:0 2px 10px rgba(0,0,0,.32), 0 0 0 1px rgba(0,0,0,.16) !important;
}
html[data-msb-appearance] body .mf-media-shell > .mf-head--on-media .post-card-menu-btn.pcm-on-dark-media,
html[data-msb-appearance] body .standard-media-topbar .post-card-menu-btn.pcm-on-dark-media,
html[data-msb-appearance] body.news-page .post.public-post-card .standard-media-topbar .post-card-menu-btn.pcm-on-dark-media{
  background:rgba(255,255,255,.78) !important;
  border-color:rgba(15,23,42,.14) !important;
  box-shadow:0 2px 10px rgba(0,0,0,.28), 0 0 0 1px rgba(255,255,255,.28) !important;
  color:#0f172a !important;
}
html[data-msb-appearance] body .mf-media-shell > .mf-head--on-media .post-card-menu-btn i,
html[data-msb-appearance] body .standard-media-topbar .post-card-menu-btn i,
html[data-msb-appearance] body.news-page .post.public-post-card .standard-media-topbar .post-card-menu-btn i{
  color:inherit !important;
  text-shadow:0 1px 2px rgba(0,0,0,.35) !important;
  background:transparent !important;
  border:0 !important;
  box-shadow:none !important;
  backdrop-filter:none !important;
  -webkit-backdrop-filter:none !important;
}
html[data-msb-appearance] body .mf-media-shell > .mf-head--on-media .post-card-menu-btn.pcm-on-dark-media i,
html[data-msb-appearance] body .standard-media-topbar .post-card-menu-btn.pcm-on-dark-media i,
html[data-msb-appearance] body.news-page .post.public-post-card .standard-media-topbar .post-card-menu-btn.pcm-on-dark-media i{
  color:#0f172a !important;
  text-shadow:none !important;
}

/* On-media post header — shared sizing (public, news, feed, profile) */
.post.public-post-card .standard-media-topbar > .post-card-menu-wrap,
body .mf-feed .mf-card .mf-media-shell > .mf-head--on-media > .post-card-menu-wrap,
body .mf-feed .mf-card .mf-media-shell > .mf-head--on-media > .mf-menu-wrap.post-card-menu-wrap,
.mf-feed .mf-card .mf-media-shell > .mf-head--on-media > .post-card-menu-wrap,
.mf-feed .mf-card .mf-media-shell > .mf-head--on-media > .mf-menu-wrap.post-card-menu-wrap,
body #profilePostsFeed .mf-card .mf-media-shell > .mf-head--on-media > .post-card-menu-wrap,
body #profilePostsFeed .mf-card .mf-media-shell > .mf-head--on-media > .mf-menu-wrap.post-card-menu-wrap,
#profilePostsFeed .mf-card .mf-media-shell > .mf-head--on-media > .post-card-menu-wrap,
#profilePostsFeed .mf-card .mf-media-shell > .mf-head--on-media > .mf-menu-wrap.post-card-menu-wrap,
.mf-card .mf-head--on-media > .post-card-menu-wrap,
.mf-card .mf-head--on-media > .mf-menu-wrap.post-card-menu-wrap{
  flex:0 0 var(--pcm-on-media-circle-size) !important;
  width:var(--pcm-on-media-circle-size) !important;
  margin-left:auto !important;
  margin-right:-5px !important;
  <!-- margin-top:-22px !important; -->
  position:relative !important;
  top:auto !important;
  right:auto !important;
  transform:none !important;
  z-index:60 !important;
}
.post.public-post-card:not(.is-reel-post) .media-stage:has(> .standard-media-topbar),
.mf-feed .mf-card .mf-media-shell:has(> .mf-head--on-media),
#profilePostsFeed .mf-card .mf-media-shell:has(> .mf-head--on-media){
  overflow:visible !important;
}
/* On-media — dark frosted 3-dot menu (readable on light + dark media) */
.post.public-post-card .standard-media-topbar .post-card-menu-btn,
body .mf-feed .mf-card .mf-head--on-media .post-card-menu-btn,
body #profilePostsFeed .mf-card .mf-head--on-media .post-card-menu-btn,
.mf-card .mf-head--on-media .post-card-menu-btn{
  width:var(--pcm-on-media-circle-size) !important;
  height:var(--pcm-on-media-circle-size) !important;
  min-width:var(--pcm-on-media-circle-size) !important;
  min-height:var(--pcm-on-media-circle-size) !important;
  padding:0 !important;
  flex:0 0 var(--pcm-on-media-circle-size) !important;
  border-radius:50% !important;
  border:1px solid rgba(255,255,255,.34) !important;
  background:rgba(15,23,42,.52) !important;
  backdrop-filter:blur(10px) !important;
  -webkit-backdrop-filter:blur(10px) !important;
  box-shadow:0 2px 10px rgba(0,0,0,.32), 0 0 0 1px rgba(0,0,0,.16) !important;
  color:#fff !important;
  display:inline-flex !important;
  align-items:center !important;
  justify-content:center !important;
}
.post.public-post-card .standard-media-topbar .post-card-menu-btn:hover,
.post.public-post-card .standard-media-topbar .post-card-menu-btn:focus,
body .mf-feed .mf-card .mf-head--on-media .post-card-menu-btn:hover,
body .mf-feed .mf-card .mf-head--on-media .post-card-menu-btn:focus,
body #profilePostsFeed .mf-card .mf-head--on-media .post-card-menu-btn:hover,
body #profilePostsFeed .mf-card .mf-head--on-media .post-card-menu-btn:focus,
.mf-card .mf-head--on-media .post-card-menu-btn:hover,
.mf-card .mf-head--on-media .post-card-menu-btn:focus{
  background:rgba(15,23,42,.68) !important;
  border-color:rgba(255,255,255,.46) !important;
  box-shadow:0 4px 14px rgba(0,0,0,.38), 0 0 0 1px rgba(0,0,0,.2) !important;
  outline:none !important;
}
.post.public-post-card .standard-media-topbar .post-card-menu-btn i,
body .mf-feed .mf-card .mf-head--on-media .post-card-menu-btn i,
body #profilePostsFeed .mf-card .mf-head--on-media .post-card-menu-btn i,
.mf-card .mf-head--on-media .post-card-menu-btn i{
  color:#fff !important;
  font-size:16px !important;
  line-height:1 !important;
  text-shadow:0 1px 2px rgba(0,0,0,.35), 0 0 1px rgba(0,0,0,.45) !important;
  background:transparent !important;
  border:0 !important;
  box-shadow:none !important;
}
.post.public-post-card .standard-media-topbar .post-card-menu-btn .fa-ellipsis-v,
body .mf-feed .mf-card .mf-head--on-media .post-card-menu-btn .fa-ellipsis-v,
body #profilePostsFeed .mf-card .mf-head--on-media .post-card-menu-btn .fa-ellipsis-v,
.mf-card .mf-head--on-media .post-card-menu-btn .fa-ellipsis-v{
  transform:rotate(90deg);
}
/* Media action — circular + / Sent on media (matches frosted 3-dot menu) */
.post.public-post-card .media-stage > .standard-media-top-actions .mf-media-action-circle,
.post.public-post-card .media-stage > .standard-media-top-actions .mf-publisher-follow-circle,
.mf-feed .mf-card .mf-media-shell > .mf-media-top-actions .mf-media-action-circle,
.mf-feed .mf-card .mf-media-shell > .mf-media-top-actions .mf-publisher-follow-circle,
#profilePostsFeed .mf-card .mf-media-shell > .mf-media-top-actions .mf-media-action-circle,
#profilePostsFeed .mf-card .mf-media-shell > .mf-media-top-actions .mf-publisher-follow-circle,
.mf-card .mf-media-shell > .mf-media-top-actions .mf-media-action-circle,
.mf-card .mf-media-shell > .mf-media-top-actions .mf-publisher-follow-circle{
  width:var(--pcm-on-media-circle-size) !important;
  height:var(--pcm-on-media-circle-size) !important;
  min-width:var(--pcm-on-media-circle-size) !important;
  min-height:var(--pcm-on-media-circle-size) !important;
  padding:0 !important;
  flex:0 0 var(--pcm-on-media-circle-size) !important;
  border-radius:50% !important;
  border:1px solid rgba(255,255,255,.34) !important;
  background:rgba(15,23,42,.52) !important;
  backdrop-filter:blur(10px) !important;
  -webkit-backdrop-filter:blur(10px) !important;
  box-shadow:0 2px 10px rgba(0,0,0,.32), 0 0 0 1px rgba(0,0,0,.16) !important;
  color:#fff !important;
  font-size:0 !important;
  line-height:1 !important;
  margin:0 !important;
  display:inline-flex !important;
  align-items:center !important;
  justify-content:center !important;
  pointer-events:auto !important;
  white-space:nowrap !important;
}
.post.public-post-card .media-stage > .standard-media-top-actions .mf-media-action-circle:hover,
.post.public-post-card .media-stage > .standard-media-top-actions .mf-media-action-circle:focus,
.post.public-post-card .media-stage > .standard-media-top-actions .mf-publisher-follow-circle:hover,
.post.public-post-card .media-stage > .standard-media-top-actions .mf-publisher-follow-circle:focus,
.mf-feed .mf-card .mf-media-shell > .mf-media-top-actions .mf-media-action-circle:hover,
.mf-feed .mf-card .mf-media-shell > .mf-media-top-actions .mf-media-action-circle:focus,
.mf-feed .mf-card .mf-media-shell > .mf-media-top-actions .mf-publisher-follow-circle:hover,
.mf-feed .mf-card .mf-media-shell > .mf-media-top-actions .mf-publisher-follow-circle:focus,
#profilePostsFeed .mf-card .mf-media-shell > .mf-media-top-actions .mf-media-action-circle:hover,
#profilePostsFeed .mf-card .mf-media-shell > .mf-media-top-actions .mf-media-action-circle:focus,
#profilePostsFeed .mf-card .mf-media-shell > .mf-media-top-actions .mf-publisher-follow-circle:hover,
#profilePostsFeed .mf-card .mf-media-shell > .mf-media-top-actions .mf-publisher-follow-circle:focus,
.mf-card .mf-media-shell > .mf-media-top-actions .mf-media-action-circle:hover,
.mf-card .mf-media-shell > .mf-media-top-actions .mf-media-action-circle:focus,
.mf-card .mf-media-shell > .mf-media-top-actions .mf-publisher-follow-circle:hover,
.mf-card .mf-media-shell > .mf-media-top-actions .mf-publisher-follow-circle:focus{
  background:rgba(15,23,42,.68) !important;
  border-color:rgba(255,255,255,.42) !important;
  outline:none !important;
}
.post.public-post-card .media-stage > .standard-media-top-actions .mf-media-action-circle i,
.post.public-post-card .media-stage > .standard-media-top-actions .mf-publisher-follow-circle i,
.mf-feed .mf-card .mf-media-shell > .mf-media-top-actions .mf-media-action-circle i,
.mf-feed .mf-card .mf-media-shell > .mf-media-top-actions .mf-publisher-follow-circle i,
#profilePostsFeed .mf-card .mf-media-shell > .mf-media-top-actions .mf-media-action-circle i,
#profilePostsFeed .mf-card .mf-media-shell > .mf-media-top-actions .mf-publisher-follow-circle i,
.mf-card .mf-media-shell > .mf-media-top-actions .mf-media-action-circle i,
.mf-card .mf-media-shell > .mf-media-top-actions .mf-publisher-follow-circle i{
  color:#fff !important;
  font-size:16px !important;
  font-weight:700 !important;
  line-height:1 !important;
  text-shadow:0 1px 2px rgba(0,0,0,.35) !important;
}
html[data-msb-appearance] body .publisher-follow-btn.mf-media-action-circle.primary,
html[data-msb-appearance] body .publisher-follow-btn.mf-publisher-follow-circle.primary,
html[data-msb-appearance] body .mf-media-action-circle.primary,
html[data-msb-appearance] body .mf-publisher-follow-circle.primary{
  background:rgba(15,23,42,.52) !important;
  border-color:rgba(255,255,255,.34) !important;
  color:#fff !important;
}
.mf-media-action-circle.is-pending,
.mf-media-action-circle.is-following,
.mf-publisher-follow-circle.is-pending,
.mf-publisher-follow-circle.is-following{
  cursor:default !important;
}
.mf-media-action-circle .mf-media-action-label,
.mf-media-action-circle .mf-publisher-follow-label,
.mf-publisher-follow-circle .mf-media-action-label,
.mf-publisher-follow-circle .mf-publisher-follow-label{
  display:block;
  font-size:10px !important;
  font-weight:800 !important;
  line-height:1 !important;
  letter-spacing:.01em;
  color:#fff !important;
  text-shadow:0 1px 2px rgba(0,0,0,.35) !important;
  white-space:nowrap;
}
.mf-media-action-circle.is-pending,
.mf-publisher-follow-circle.is-pending{
  background:rgba(15,23,42,.52) !important;
  border-color:rgba(255,255,255,.34) !important;
  box-shadow:0 2px 10px rgba(0,0,0,.32), 0 0 0 1px rgba(0,0,0,.16) !important;
}
/* Adaptive variant when media behind the button is dark */
.post.public-post-card .standard-media-topbar .post-card-menu-btn.pcm-on-dark-media,
body .mf-feed .mf-card .mf-head--on-media .post-card-menu-btn.pcm-on-dark-media,
body #profilePostsFeed .mf-card .mf-head--on-media .post-card-menu-btn.pcm-on-dark-media,
.mf-card .mf-head--on-media .post-card-menu-btn.pcm-on-dark-media{
  background:rgba(255,255,255,.78) !important;
  border-color:rgba(15,23,42,.14) !important;
  box-shadow:0 2px 10px rgba(0,0,0,.28), 0 0 0 1px rgba(255,255,255,.28) !important;
  color:#0f172a !important;
}
.post.public-post-card .standard-media-topbar .post-card-menu-btn.pcm-on-dark-media i,
body .mf-feed .mf-card .mf-head--on-media .post-card-menu-btn.pcm-on-dark-media i,
body #profilePostsFeed .mf-card .mf-head--on-media .post-card-menu-btn.pcm-on-dark-media i,
.mf-card .mf-head--on-media .post-card-menu-btn.pcm-on-dark-media i{
  color:#0f172a !important;
  text-shadow:none !important;
}
.post.public-post-card .standard-media-topbar .post-card-menu-btn.pcm-on-dark-media:hover,
.post.public-post-card .standard-media-topbar .post-card-menu-btn.pcm-on-dark-media:focus,
body .mf-feed .mf-card .mf-head--on-media .post-card-menu-btn.pcm-on-dark-media:hover,
body .mf-feed .mf-card .mf-head--on-media .post-card-menu-btn.pcm-on-dark-media:focus,
body #profilePostsFeed .mf-card .mf-head--on-media .post-card-menu-btn.pcm-on-dark-media:hover,
body #profilePostsFeed .mf-card .mf-head--on-media .post-card-menu-btn.pcm-on-dark-media:focus,
.mf-card .mf-head--on-media .post-card-menu-btn.pcm-on-dark-media:hover,
.mf-card .mf-head--on-media .post-card-menu-btn.pcm-on-dark-media:focus{
  background:rgba(255,255,255,.92) !important;
  border-color:rgba(15,23,42,.18) !important;
}
.post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions,
.mf-feed .mf-card .mf-media-shell > .mf-media-top-actions,
#profilePostsFeed .mf-card .mf-media-shell > .mf-media-top-actions,
.mf-card .mf-media-shell > .mf-media-top-actions{
  position:absolute !important;
  top:22px !important;
  right:calc(14px + var(--pcm-on-media-circle-size) + 8px) !important;
  z-index:40 !important;
  display:flex !important;
  align-items:center !important;
  gap:8px !important;
  pointer-events:none !important;
  margin:0 !important;
}
.post.public-post-card[data-is-publisher="1"] .media-stage > .standard-media-top-actions .publisher-follow-btn:not(.mf-media-action-circle),
.post.public-post-card[data-is-publisher="1"] .media-stage > .standard-media-top-actions .friend-btn,
.post.public-post-card[data-is-publisher="1"] .post-card-head-actions .publisher-follow-btn,
.post.public-post-card[data-is-publisher="1"] .standard-text-top-actions .publisher-follow-btn,
.mf-feed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-media-top-actions .publisher-follow-btn:not(.mf-media-action-circle),
.mf-feed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-media-top-actions .mf-friend-btn:not(.mf-media-action-circle),
.mf-feed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-media-top-actions .mf-publisher-follow:not(.mf-media-action-circle),
.mf-feed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-media-top-actions .publisher-follow-btn:not(.mf-media-action-circle),
.mf-feed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-media-top-actions .mf-friend-btn:not(.mf-media-action-circle),
.mf-feed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-media-top-actions .mf-publisher-follow:not(.mf-media-action-circle),
#profilePostsFeed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-media-top-actions .publisher-follow-btn:not(.mf-media-action-circle),
#profilePostsFeed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-media-top-actions .mf-friend-btn:not(.mf-media-action-circle),
#profilePostsFeed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-media-top-actions .mf-publisher-follow:not(.mf-media-action-circle),
#profilePostsFeed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-media-top-actions .publisher-follow-btn:not(.mf-media-action-circle),
#profilePostsFeed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-media-top-actions .mf-friend-btn:not(.mf-media-action-circle),
#profilePostsFeed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-media-top-actions .mf-publisher-follow:not(.mf-media-action-circle),
.mf-card[data-is-publisher="1"] .mf-head:not(.mf-head--on-media) .publisher-follow-btn,
.mf-card[data-is-publisher="1"] .mf-head:not(.mf-head--on-media) .mf-publisher-follow,
.mf-card[data-account-kind="publisher"] .mf-head:not(.mf-head--on-media) .publisher-follow-btn,
.mf-card[data-account-kind="publisher"] .mf-head:not(.mf-head--on-media) .mf-publisher-follow{
  padding:7px 12px !important;
  font-size:11px !important;
  line-height:1 !important;
  font-weight:700 !important;
  flex-shrink:0 !important;
  pointer-events:auto !important;
  margin:0 !important;
  display:inline-flex !important;
  align-items:center !important;
  justify-content:center !important;
  white-space:nowrap !important;
  box-shadow:0 4px 14px rgba(15,23,42,.24) !important;
}
.post.public-post-card[data-is-publisher="1"] .media-stage > .standard-media-top-actions .publisher-follow-btn.primary,
.post.public-post-card[data-is-publisher="1"] .media-stage > .standard-media-top-actions .friend-btn.primary{
  margin-top:11px !important;
  margin-right:0 !important;
}
.mf-feed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-media-top-actions .publisher-follow-btn.primary,
.mf-feed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-media-top-actions .mf-friend-btn.primary,
.mf-feed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-media-top-actions .mf-publisher-follow:not(.is-following):not(.mf-media-action-circle),
.mf-feed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-media-top-actions .publisher-follow-btn.primary,
.mf-feed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-media-top-actions .mf-friend-btn.primary,
.mf-feed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-media-top-actions .mf-publisher-follow:not(.is-following):not(.mf-media-action-circle),
#profilePostsFeed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-media-top-actions .publisher-follow-btn.primary,
#profilePostsFeed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-media-top-actions .mf-friend-btn.primary,
#profilePostsFeed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-media-top-actions .mf-publisher-follow:not(.is-following):not(.mf-media-action-circle),
#profilePostsFeed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-media-top-actions .publisher-follow-btn.primary,
#profilePostsFeed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-media-top-actions .mf-friend-btn.primary,
#profilePostsFeed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-media-top-actions .mf-publisher-follow:not(.is-following):not(.mf-media-action-circle){
  margin-top:5px !important;
  margin-right:-5px !important;
}
.mf-feed .mf-card .mf-media-shell > .mf-media-top-actions .mf-media-action-circle,
.mf-feed .mf-card .mf-media-shell > .mf-media-top-actions .mf-publisher-follow-circle,
#profilePostsFeed .mf-card .mf-media-shell > .mf-media-top-actions .mf-media-action-circle,
#profilePostsFeed .mf-card .mf-media-shell > .mf-media-top-actions .mf-publisher-follow-circle,
.post.public-post-card .media-stage > .standard-media-top-actions .mf-media-action-circle,
.post.public-post-card .media-stage > .standard-media-top-actions .mf-publisher-follow-circle{
  margin-top:5px !important;
  margin-right:0 !important;
}
@media (max-width:767.98px){
  .post.public-post-card[data-is-publisher="1"] .media-stage > .standard-media-top-actions .publisher-follow-btn:not(.mf-media-action-circle),
  .post.public-post-card[data-is-publisher="1"] .media-stage > .standard-media-top-actions .friend-btn,
  .post.public-post-card[data-is-publisher="1"] .post-card-head-actions .publisher-follow-btn:not(.mf-media-action-circle),
  .mf-feed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-media-top-actions .publisher-follow-btn:not(.mf-media-action-circle),
  .mf-feed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-media-top-actions .mf-friend-btn:not(.mf-media-action-circle),
  .mf-feed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-media-top-actions .mf-publisher-follow:not(.mf-media-action-circle),
  .mf-feed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-media-top-actions .publisher-follow-btn:not(.mf-media-action-circle),
  .mf-feed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-media-top-actions .mf-friend-btn:not(.mf-media-action-circle),
  .mf-feed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-media-top-actions .mf-publisher-follow:not(.mf-media-action-circle),
  #profilePostsFeed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-media-top-actions .publisher-follow-btn:not(.mf-media-action-circle),
  #profilePostsFeed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-media-top-actions .mf-friend-btn:not(.mf-media-action-circle),
  #profilePostsFeed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-media-top-actions .mf-publisher-follow:not(.mf-media-action-circle),
  #profilePostsFeed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-media-top-actions .publisher-follow-btn:not(.mf-media-action-circle),
  #profilePostsFeed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-media-top-actions .mf-friend-btn:not(.mf-media-action-circle),
  #profilePostsFeed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-media-top-actions .mf-publisher-follow:not(.mf-media-action-circle){
    padding:6px 10px !important;
    font-size:10px !important;
  }
  .mf-media-action-circle, .mf-publisher-follow-circle{
    width:var(--pcm-on-media-circle-size) !important;
    height:var(--pcm-on-media-circle-size) !important;
    min-width:var(--pcm-on-media-circle-size) !important;
    min-height:var(--pcm-on-media-circle-size) !important;
    padding:0 !important;
    font-size:0 !important;
    flex:0 0 var(--pcm-on-media-circle-size) !important;
  }
}
@media (min-width:1025px){
  .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions,
  .mf-feed .mf-card .mf-media-shell > .mf-media-top-actions,
  #profilePostsFeed .mf-card .mf-media-shell > .mf-media-top-actions{
    top:12px !important;
    right:calc(14px + var(--pcm-on-media-circle-size) + 8px) !important;
  }
  .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .publisher-follow-btn:not(.mf-media-action-circle),
  .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .friend-btn{
    padding:7px 12px !important;
    font-size:11px !important;
    margin-top:11px !important;
    margin-right:0 !important;
  }
  .mf-feed .mf-card .mf-media-shell > .mf-media-top-actions .publisher-follow-btn:not(.mf-media-action-circle),
  .mf-feed .mf-card .mf-media-shell > .mf-media-top-actions .mf-friend-btn:not(.mf-media-action-circle),
  .mf-feed .mf-card .mf-media-shell > .mf-media-top-actions .mf-publisher-follow:not(.mf-media-action-circle),
  #profilePostsFeed .mf-card .mf-media-shell > .mf-media-top-actions .publisher-follow-btn:not(.mf-media-action-circle),
  #profilePostsFeed .mf-card .mf-media-shell > .mf-media-top-actions .mf-friend-btn:not(.mf-media-action-circle),
  #profilePostsFeed .mf-card .mf-media-shell > .mf-media-top-actions .mf-publisher-follow:not(.mf-media-action-circle){
    padding:7px 12px !important;
    font-size:11px !important;
    margin-top:24px !important;
    margin-right:0 !important;
  }
}

/* Post 3-dot dropdown — follow Appearance (Dark / Light / palette) */
html.dark-auto:not([data-msb-appearance]) .post-card-menu,
html[data-theme="dark"]:not([data-msb-appearance]) .post-card-menu{
  --pcm-menu-bg:var(--msb-palette-surface-2, #1d2530);
  --pcm-menu-border:var(--msb-palette-border, rgba(255,255,255,.12));
  --pcm-menu-shadow:0 18px 44px rgba(0,0,0,.42);
  --pcm-menu-text:var(--msb-palette-text, #e6edf3);
  --pcm-menu-text-hover:var(--msb-palette-text, #f3f6fb);
  --pcm-menu-hover-bg:var(--msb-palette-hover-bg, rgba(255,255,255,.08));
  --pcm-menu-divider:var(--msb-palette-border, rgba(255,255,255,.12));
  background:var(--pcm-menu-bg) !important;
  border-color:var(--pcm-menu-border) !important;
  box-shadow:var(--pcm-menu-shadow) !important;
  color:var(--pcm-menu-text) !important;
}
html[data-msb-appearance] .post-card-menu,
html[data-msb-appearance] .mf-menu.post-card-menu{
  --pcm-menu-bg:var(--msb-palette-surface-2);
  --pcm-menu-border:var(--msb-palette-border);
  --pcm-menu-shadow:0 18px 44px rgba(15,23,42,.18);
  --pcm-menu-text:var(--msb-palette-text);
  --pcm-menu-text-hover:var(--msb-palette-text);
  --pcm-menu-hover-bg:var(--msb-palette-hover-bg);
  --pcm-menu-divider:var(--msb-palette-border);
  background:var(--pcm-menu-bg) !important;
  border-color:var(--pcm-menu-border) !important;
  box-shadow:var(--pcm-menu-shadow) !important;
  color:var(--pcm-menu-text) !important;
}
html.dark-auto:not([data-msb-appearance]) .post-card-menu .pcm-item,
html[data-theme="dark"]:not([data-msb-appearance]) .post-card-menu .pcm-item,
html[data-msb-appearance] .post-card-menu .pcm-item,
html[data-msb-appearance] .mf-menu.post-card-menu .pcm-item,
html.dark-auto:not([data-msb-appearance]) .post-card-menu .pcm-item i,
html[data-theme="dark"]:not([data-msb-appearance]) .post-card-menu .pcm-item i,
html[data-msb-appearance] .post-card-menu .pcm-item i,
html[data-msb-appearance] .post-card-menu .pcm-item .icon,
html.dark-auto:not([data-msb-appearance]) .post-card-menu .pcm-item span,
html[data-theme="dark"]:not([data-msb-appearance]) .post-card-menu .pcm-item span,
html[data-msb-appearance] .post-card-menu .pcm-item span{
  color:var(--pcm-menu-text) !important;
}
html.dark-auto:not([data-msb-appearance]) .post-card-menu .pcm-item:hover,
html[data-theme="dark"]:not([data-msb-appearance]) .post-card-menu .pcm-item:hover,
html[data-msb-appearance] .post-card-menu .pcm-item:hover,
html.dark-auto:not([data-msb-appearance]) .post-card-menu .pcm-item:focus,
html[data-theme="dark"]:not([data-msb-appearance]) .post-card-menu .pcm-item:focus,
html[data-msb-appearance] .post-card-menu .pcm-item:focus{
  background:var(--pcm-menu-hover-bg) !important;
  color:var(--pcm-menu-text-hover) !important;
}
html.dark-auto:not([data-msb-appearance]) .post-card-menu .pcm-item:hover i,
html[data-theme="dark"]:not([data-msb-appearance]) .post-card-menu .pcm-item:hover i,
html[data-msb-appearance] .post-card-menu .pcm-item:hover i,
html.dark-auto:not([data-msb-appearance]) .post-card-menu .pcm-item:hover span,
html[data-theme="dark"]:not([data-msb-appearance]) .post-card-menu .pcm-item:hover span,
html[data-msb-appearance] .post-card-menu .pcm-item:hover span{
  color:var(--pcm-menu-text-hover) !important;
}
html.dark-auto:not([data-msb-appearance]) .post-card-menu .pcm-divider,
html[data-theme="dark"]:not([data-msb-appearance]) .post-card-menu .pcm-divider,
html[data-msb-appearance] .post-card-menu .pcm-divider{
  background:var(--pcm-menu-divider) !important;
}

html[data-theme="light"]:not([data-msb-appearance]) .post-card-menu{
  --pcm-menu-bg:#fff8f3;
  --pcm-menu-border:rgba(107,58,30,.08);
  --pcm-menu-shadow:0 16px 40px rgba(92,61,46,.18);
  --pcm-menu-text:#6b3a1e;
  --pcm-menu-text-hover:#5c2f16;
  --pcm-menu-hover-bg:rgba(107,58,30,.08);
  --pcm-menu-divider:rgba(107,58,30,.12);
  background:var(--pcm-menu-bg) !important;
  border-color:var(--pcm-menu-border) !important;
  color:var(--pcm-menu-text) !important;
}
html[data-theme="light"]:not([data-msb-appearance]) .post-card-menu .pcm-item,
html[data-theme="light"]:not([data-msb-appearance]) .post-card-menu .pcm-item i,
html[data-theme="light"]:not([data-msb-appearance]) .post-card-menu .pcm-item span{
  color:var(--pcm-menu-text) !important;
}
