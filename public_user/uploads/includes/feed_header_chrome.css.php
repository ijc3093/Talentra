<?php
declare(strict_types=1);
?>
/* Shared feed header chrome (feed.php, public.php, shop.php) */
.ig-feed-header{
  position:relative;
  display:flex;
  justify-content:center;
  align-items:flex-start;
  width:100%;
  margin:0 0 12px;
  padding:16px 16px 14px;
  background:var(--feed-surface, var(--msb-palette-bg, var(--feed-page-bg, var(--feed-topbar-bg, #fff))));
  box-sizing:border-box;
}
.ig-feed-top-lead{
  position:absolute;
  left:16px;
  top:50%;
  transform:translateY(-50%);
  display:flex;
  align-items:center;
  gap:10px;
  z-index:2;
  padding:0;
  box-sizing:border-box;
  max-width:min(72vw, 520px);
}
.ig-feed-top-actions{
  position:absolute;
  right:16px;
  top:50%;
  transform:translateY(-50%);
  display:flex;
  align-items:center;
  gap:10px;
  z-index:2;
  padding:0;
  box-sizing:border-box;
  max-width:min(52vw, 520px);
}
.ig-feed-account-badge{
  display:inline-flex;
  align-items:center;
  max-width:min(22vw, 160px);
  padding:0 10px;
  min-height:32px;
  border-radius:999px;
  background:var(--feed-control-soft, #eef2f7);
  border:1px solid var(--feed-control-border, #dbe3ee);
  color:var(--feed-text, #1e293b);
  font-size:12px;
  font-weight:700;
  letter-spacing:.02em;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  flex-shrink:1;
}
.ig-stories-wrap{
  width:100%;
  max-width:614px;
  margin:0 auto;
  padding:0;
  box-sizing:border-box;
  min-height:44px;
}
.ig-top-act{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:0;
  border:0;
  background:transparent;
  color:var(--feed-text, #1e293b);
  cursor:pointer;
  flex-shrink:0;
  line-height:1;
  text-decoration:none;
  box-sizing:border-box;
  transition:background .15s ease,opacity .15s ease;
}
.ig-top-act:hover{opacity:.85;}
.ig-top-mic,
.ig-top-shop,
.ig-top-cart{
  width:44px;
  height:44px;
  border-radius:50%;
  background:var(--feed-control-soft, #eef2f7);
  font-size:18px;
}
.ig-top-mic:hover,
.ig-top-shop:hover,
.ig-top-cart:hover{background:var(--feed-surface-alt, #e2e8f0);opacity:1;}
.ig-top-shop.is-active,
.ig-top-cart.is-active{
  background:var(--feed-accent-soft, rgba(37,99,235,.12));
  box-shadow:inset 0 0 0 1px rgba(37,99,235,.25);
}
.ig-top-live{
  gap:8px;
  min-height:44px;
  padding:0 18px;
  border-radius:999px;
  background:var(--feed-control-soft, #eef2f7);
  border:1px solid var(--feed-control-border, #dbe3ee);
  font-size:15px;
  font-weight:800;
  letter-spacing:-.01em;
  color:var(--feed-text, #1e293b);
}
.ig-top-live i{font-size:16px;}
.ig-top-live:hover{background:var(--feed-surface-alt, #e2e8f0);opacity:1;color:var(--feed-text, #1e293b);}
body.feed-page.feed-insta-ui .sh-mainpanel{
  margin-left:var(--feedRailW, 84px);
}
body.shop-page.feed-insta-ui .ig-feed-header{
  background:var(--shop-surface, var(--msb-palette-bg, var(--feed-page-bg, var(--feed-topbar-bg, #fff))));
  color:var(--shop-text, var(--msb-palette-text, inherit));
  border-bottom-color:var(--shop-border, var(--msb-palette-border, rgba(15,23,42,.08)));
}
body.shop-page.feed-insta-ui .ig-feed-top-lead{
  background:transparent;
  color:var(--shop-text, var(--msb-palette-text, inherit));
}
body.shop-page.feed-insta-ui .ig-feed-user-name{
  background:transparent !important;
  color:var(--shop-text, var(--msb-palette-text, var(--text-primary, #111827))) !important;
  -webkit-text-fill-color:var(--shop-text, var(--msb-palette-text, var(--text-primary, #111827))) !important;
}
body.shop-page.feed-insta-ui .ig-feed-user-name:hover,
body.shop-page.feed-insta-ui .ig-feed-user-name:focus{
  color:var(--shop-link, var(--msb-palette-link, var(--msb-palette-action, #2563eb))) !important;
  -webkit-text-fill-color:var(--shop-link, var(--msb-palette-link, var(--msb-palette-action, #2563eb))) !important;
}
body.shop-page.feed-insta-ui .sh-pagebody{
  padding-top:0;
}
body.shop-page.feed-insta-ui .shop-header-search-wrap{
  display:block !important;
  min-height:44px;
  padding:0 8px;
  box-sizing:border-box;
}
body.shop-page.feed-insta-ui .shop-header-search{
  width:100%;
  padding:0;
  margin:0;
  position:relative;
  top:auto;
  z-index:auto;
  background:transparent;
  box-sizing:border-box;
}
body.shop-page.feed-insta-ui .shop-header-search .feed-top-search-form{
  width:100%;
  max-width:100%;
  margin:0;
}
body.shop-page.feed-insta-ui .shop-header-search .feed-top-search-field{
  position:relative;
  width:100%;
}
body.shop-page.feed-insta-ui .shop-header-search .feed-top-search-input{
  width:100%;
  min-width:0;
  height:42px;
  border:1px solid var(--feed-control-border, rgba(15,23,42,.14));
  border-radius:999px;
  padding:0 44px 0 16px;
  font-size:14px;
  background:var(--feed-control-bg, #fff);
  color:var(--feed-topbar-text, #0d0d0d);
  outline:none;
  box-sizing:border-box;
}
body.shop-page.feed-insta-ui .shop-header-search .feed-top-search-input::placeholder{
  color:var(--feed-control-placeholder, #667085);
}
body.shop-page.feed-insta-ui .shop-header-search .feed-top-search-input:focus{
  border-color:var(--msb-palette-action, var(--feed-accent, #2563eb));
  box-shadow:0 0 0 3px var(--msb-palette-action-soft, var(--feed-accent-soft, rgba(37,99,235,.12)));
}
body.shop-page.feed-insta-ui .shop-header-search .feed-top-search-icon{
  position:absolute;
  right:6px;
  top:50%;
  transform:translateY(-50%);
  width:32px;
  height:32px;
  border:0;
  border-radius:50%;
  padding:0;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  background:transparent;
  color:var(--msb-palette-action, var(--feed-accent, #2563eb));
  cursor:pointer;
  line-height:1;
}
body.shop-page.feed-insta-ui .shop-header-search .feed-top-search-icon i{
  font-size:15px;
  line-height:1;
}
body.shop-page.feed-insta-ui .shop-header-search .feed-top-search-icon:hover,
body.shop-page.feed-insta-ui .shop-header-search .feed-top-search-icon:focus{
  background:var(--msb-palette-action-soft, var(--feed-accent-soft, rgba(37,99,235,.12)));
}
body.shop-page.feed-insta-ui .ig-feed-top-actions{
  align-items:flex-end;
  padding-bottom:2px;
}
body.shop-page.feed-insta-ui .ig-shop-view-toggle{
  display:inline-flex;
  align-items:flex-end;
  gap:10px;
  margin-right:2px;
}
body.shop-page.feed-insta-ui .ig-shop-view-btn{
  display:inline-flex;
  flex-direction:column;
  align-items:center;
  gap:2px;
  border:0;
  background:transparent;
  padding:0;
  cursor:pointer;
  color:var(--shop-text-muted, var(--msb-palette-text-muted, #9ca3af));
  line-height:1;
}
body.shop-page.feed-insta-ui .ig-shop-view-btn .ig-shop-view-ic{
  width:22px;
  height:22px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
}
body.shop-page.feed-insta-ui .ig-shop-view-btn .ig-shop-view-ic svg{
  width:20px;
  height:20px;
  fill:currentColor;
}
body.shop-page.feed-insta-ui .ig-shop-view-btn .ig-shop-view-label{
  font-size:11px;
  font-weight:600;
  letter-spacing:.01em;
}
body.shop-page.feed-insta-ui .ig-shop-view-btn.is-active{
  color:var(--msb-palette-action, #f97316);
}
body.shop-page.feed-insta-ui .ig-shop-view-btn.is-active .ig-shop-view-label{
  color:var(--shop-text, var(--msb-palette-text, var(--text-primary, #111827)));
}
body.shop-page.feed-insta-ui .ig-shop-view-btn:hover{
  opacity:.85;
}
body.shop-page.feed-insta-ui .feed-left-rail-page-head{
  padding:0 12px 10px;
  margin:14px 0 0;
  border-bottom:1px solid var(--shop-border, var(--msb-palette-border, rgba(15,23,42,.08)));
}
body.shop-page.feed-insta-ui .feed-left-rail-page-title{
  margin:0;
  font-size:20px;
  font-weight:800;
  line-height:1.2;
  color:var(--shop-text, var(--msb-palette-text, #111827));
}
body.shop-page.feed-insta-ui .feed-left-rail-page-sub{
  margin:4px 0 0;
  font-size:12px;
  line-height:1.35;
  color:var(--shop-text-muted, var(--msb-palette-text-muted, #6b7280));
}
body.shop-page.feed-insta-ui .shop-page-head-mobile{display:none;}
body.shop-page.feed-insta-ui .shop-nav-filters{
  margin-top:8px;
  border-top:1px solid var(--shop-border, var(--msb-palette-border, rgba(15,23,42,.08)));
  padding-top:4px;
  flex:1 1 auto;
  min-height:0;
  overflow-y:auto;
  overflow-x:hidden;
  -webkit-overflow-scrolling:touch;
  overscroll-behavior:contain;
  scrollbar-width:thin;
  scrollbar-color:rgba(0,0,0,.18) transparent;
  background:var(--shop-surface, var(--msb-palette-bg, transparent));
  color:var(--shop-text, var(--msb-palette-text, inherit));
}
body.shop-page.feed-insta-ui .shop-nav-filters::-webkit-scrollbar{width:5px;}
body.shop-page.feed-insta-ui .shop-nav-filters::-webkit-scrollbar-thumb{
  background:rgba(0,0,0,.18);
  border-radius:999px;
}
@media (min-width:1025px){
  body.shop-page.feed-insta-ui .feed-left-nav{
    overflow:hidden;
    background:var(--shop-surface, var(--msb-palette-bg, var(--feed-page-bg, transparent)));
    color:var(--shop-text, var(--msb-palette-text, var(--text-primary, inherit)));
  }
  body.shop-page.feed-insta-ui .feed-left-rail{
    background:var(--shop-surface, var(--msb-palette-bg, var(--feed-page-bg, transparent)));
    color:var(--shop-text, var(--msb-palette-text, var(--text-primary, inherit)));
  }
}
body.shop-page.feed-insta-ui .shop-nav-filter{
  border-bottom:1px solid var(--shop-border, var(--msb-palette-border, rgba(15,23,42,.08)));
}
body.shop-page.feed-insta-ui .shop-nav-filter-toggle{
  width:100%;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  padding:12px 12px;
  border:0;
  background:transparent;
  color:var(--shop-text, var(--msb-palette-text, #111827));
  font-size:14px;
  font-weight:700;
  text-align:left;
  cursor:pointer;
  box-sizing:border-box;
}
body.shop-page.feed-insta-ui .shop-nav-filter-toggle:hover{
  background:var(--shop-hover-bg, var(--msb-palette-hover-bg, rgba(15,23,42,.04)));
}
body.shop-page.feed-insta-ui .shop-nav-filter.is-active .shop-nav-filter-label{
  color:var(--shop-text, var(--msb-palette-text, #111827));
}
body.shop-page.feed-insta-ui .shop-nav-filter-chevron{
  width:8px;
  height:8px;
  border-right:2px solid var(--shop-text-muted, var(--msb-palette-text-muted, #6b7280));
  border-bottom:2px solid var(--shop-text-muted, var(--msb-palette-text-muted, #6b7280));
  transform:rotate(45deg);
  transition:transform .15s ease;
  flex-shrink:0;
  margin-top:-2px;
}
body.shop-page.feed-insta-ui .shop-nav-filter.is-open .shop-nav-filter-chevron{
  transform:rotate(-135deg);
  margin-top:2px;
}
body.shop-page.feed-insta-ui .shop-nav-filter-panel{
  padding:0 12px 10px;
  display:grid;
  gap:4px;
}
body.shop-page.feed-insta-ui .shop-nav-filter-panel[hidden]{
  display:none !important;
}
body.shop-page.feed-insta-ui .shop-nav-filter-option{
  display:block;
  padding:7px 8px;
  border-radius:6px;
  color:var(--shop-text-soft, var(--msb-palette-text-muted, #374151));
  font-size:13px;
  font-weight:500;
  text-decoration:none;
}
body.shop-page.feed-insta-ui .shop-nav-filter-option:hover{
  background:var(--shop-hover-bg, var(--msb-palette-hover-bg, rgba(15,23,42,.05)));
  color:var(--shop-text, var(--msb-palette-text, #111827));
}
body.shop-page.feed-insta-ui .shop-nav-filter-option.is-active{
  background:var(--msb-palette-action-soft, rgba(37,99,235,.1));
  color:var(--shop-link, var(--msb-palette-link, var(--msb-palette-action, #1d4ed8)));
  font-weight:700;
}
body.shop-page.feed-insta-ui .shop-nav-filter-clear,
body.shop-page.feed-insta-ui .shop-nav-filter-empty{
  display:block;
  padding:6px 8px 2px;
  font-size:12px;
  color:var(--shop-text-muted, var(--msb-palette-text-muted, #6b7280));
}
body.shop-page.feed-insta-ui .shop-nav-filter-clear{
  color:var(--shop-link, var(--msb-palette-link, var(--msb-palette-action, #2563eb)));
  text-decoration:underline;
  font-weight:600;
}
body.shop-page.feed-insta-ui .shop-nav-preferences-link{
  display:flex;
  align-items:center;
  min-height:40px;
  padding:12px 12px;
  border-bottom:1px solid var(--shop-border, var(--msb-palette-border, rgba(15,23,42,.08)));
  color:var(--shop-text, var(--msb-palette-text, #111827));
  font-size:14px;
  font-weight:800;
  text-decoration:none;
  box-sizing:border-box;
}
body.shop-page.feed-insta-ui .shop-nav-preferences-link:hover{
  background:var(--shop-hover-bg, var(--msb-palette-hover-bg, rgba(15,23,42,.04)));
  text-decoration:none;
}
body.shop-page.feed-insta-ui .shop-brand-nav{
  margin:10px 0 12px;
  padding:10px 0 4px;
  border-top:1px solid var(--shop-border, var(--msb-palette-border, rgba(177,188,206,.22)));
  border-bottom:1px solid var(--shop-border, var(--msb-palette-border, rgba(177,188,206,.22)));
  display:flex;
  flex-direction:column;
  min-height:0;
  flex:0 0 auto;
}
body.shop-page.feed-insta-ui .shop-brand-nav-list{
  max-height:min(200px, 32vh);
  overflow-y:auto;
  overflow-x:hidden;
  -webkit-overflow-scrolling:touch;
  overscroll-behavior:contain;
  touch-action:pan-y;
  padding-right:2px;
  scrollbar-width:thin;
  scrollbar-color:rgba(0,0,0,.18) transparent;
}
body.shop-page.feed-insta-ui .shop-brand-nav-list::-webkit-scrollbar{width:5px;}
body.shop-page.feed-insta-ui .shop-brand-nav-list::-webkit-scrollbar-thumb{
  background:rgba(0,0,0,.18);
  border-radius:999px;
}
body.shop-page.feed-insta-ui .shop-brand-nav-head{
  font-size:11px;
  font-weight:800;
  letter-spacing:.06em;
  text-transform:uppercase;
  color:var(--shop-text-muted, var(--msb-palette-text-muted, #64748b));
  padding:0 8px 8px;
}
body.shop-page.feed-insta-ui .shop-brand-nav-item{
  display:flex;
  align-items:center;
  gap:10px;
  padding:8px;
  margin:0 0 4px;
  border-radius:10px;
  text-decoration:none;
  color:inherit;
  transition:background .15s ease;
}
body.shop-page.feed-insta-ui .shop-brand-nav-item:hover,
body.shop-page.feed-insta-ui .shop-brand-nav-item.is-active{
  background:var(--msb-palette-action-soft, var(--shop-hover-bg, rgba(37,99,235,.08)));
  text-decoration:none;
}
body.shop-page.feed-insta-ui .shop-brand-nav-icon{
  width:34px;height:34px;border-radius:9px;flex-shrink:0;
  display:inline-flex;align-items:center;justify-content:center;
  font-size:14px;font-weight:800;color:#fff;
  background:var(--shop-brand-accent, #2563eb);
}
body.shop-page.feed-insta-ui .shop-brand-nav-text strong{
  display:block;font-size:13px;font-weight:800;color:var(--shop-text, var(--msb-palette-text, var(--text-primary, #0f172a)));line-height:1.2;
}
body.shop-page.feed-insta-ui .shop-brand-nav-text span{
  display:block;font-size:11px;color:var(--shop-text-muted, var(--msb-palette-text-muted, #64748b));margin-top:2px;
}
body.shop-page.feed-insta-ui .shop-brand-nav-clear{
  display:block;padding:6px 8px 2px;font-size:12px;font-weight:700;color:var(--shop-link, var(--msb-palette-link, var(--msb-palette-action, #2563eb)));
}
body.shop-page.feed-insta-ui .shop-brand-banner{
  display:flex;align-items:center;gap:14px;flex-wrap:wrap;
  margin:0 0 18px;padding:14px 16px;border-radius:14px;
  border:1px solid var(--shop-border, var(--msb-palette-border, rgba(177,188,206,.22)));
  background:var(--shop-card-bg, var(--msb-palette-bg, linear-gradient(135deg, rgba(255,255,255,.95), rgba(248,250,252,.98))));
  box-shadow:none;
}
body.shop-page.feed-insta-ui .shop-brand-banner-icon{
  width:44px;height:44px;border-radius:12px;flex-shrink:0;
  display:inline-flex;align-items:center;justify-content:center;
  font-size:18px;font-weight:800;color:#fff;
  background:var(--shop-brand-accent, #2563eb);
}
body.shop-page.feed-insta-ui .shop-brand-banner-text{flex:1;min-width:180px;}
body.shop-page.feed-insta-ui .shop-brand-banner-text strong{
  display:block;font-size:16px;font-weight:800;color:var(--shop-text, var(--msb-palette-text, var(--text-primary, #0f172a)));
}
body.shop-page.feed-insta-ui .shop-brand-banner-text span{
  display:block;font-size:13px;color:var(--shop-text-muted, var(--msb-palette-text-muted, #64748b));margin-top:2px;line-height:1.4;
}
body.shop-page.feed-insta-ui .shop-brand-banner-clear{
  font-size:13px;font-weight:700;color:var(--shop-link, var(--msb-palette-link, var(--msb-palette-action, #2563eb)));text-decoration:none;
}
body.shop-page.feed-insta-ui .shop-market-brand-pill{
  display:inline-flex;align-items:center;gap:6px;
  margin:0 0 8px;padding:4px 10px 4px 6px;border-radius:999px;
  font-size:11px;font-weight:800;color:var(--shop-text, var(--msb-palette-text, var(--text-primary, #0f172a)));text-decoration:none;
  background:var(--msb-palette-action-soft, var(--shop-hover-bg, rgba(15,23,42,.04)));border:1px solid var(--shop-border, var(--msb-palette-border, rgba(177,188,206,.35)));
}
body.shop-page.feed-insta-ui .shop-market-brand-pill:hover{
  background:rgba(37,99,235,.08);text-decoration:none;
}
body.shop-page.feed-insta-ui .shop-market-brand-pill-icon{
  width:20px;height:20px;border-radius:6px;flex-shrink:0;
  display:inline-flex;align-items:center;justify-content:center;
  font-size:10px;font-weight:800;color:#fff;
  background:var(--shop-brand-accent, #2563eb);
}
@media (min-width:1025px){
  body.shop-page.feed-insta-ui .feed-left-rail{
    height:min(560px, calc(100vh - 180px));
    max-height:min(560px, calc(100vh - 180px));
  }
}
@media (max-width:1024px){
  body.shop-page.feed-insta-ui .feed-left-rail-page-head{display:none;}
  body.shop-page.feed-insta-ui .shop-page-head-mobile{
    display:block;
    margin:0 0 14px;
  }
  body.shop-page.feed-insta-ui .shop-page-head-mobile .shop-page-title{
    font-size:22px;
    font-weight:800;
    padding:8px 0 0;
    margin:0;
    color:var(--shop-text, var(--msb-palette-text, #111827));
  }
  body.shop-page.feed-insta-ui .shop-page-head-mobile .shop-page-sub{
    padding:4px 0 0;
    color:var(--shop-text-muted, var(--msb-palette-text-muted, #6b7280));
    font-size:14px;
    margin:0;
  }
}
/* shop.php — fixed feed header; scrollable product list */
html:has(body.shop-page.feed-insta-ui),
body.shop-page.feed-insta-ui{
  overflow:hidden !important;
  height:100vh !important;
  max-height:100vh !important;
}
body.shop-page.feed-insta-ui .sh-mainpanel{
  height:100vh !important;
  max-height:100vh !important;
  overflow:hidden !important;
  display:flex !important;
  flex-direction:column !important;
  min-height:0 !important;
}
body.shop-page.feed-insta-ui .sh-pagebody{
  flex:1 1 auto !important;
  min-height:0 !important;
  overflow:hidden !important;
  display:flex !important;
  flex-direction:column !important;
  padding-bottom:0 !important;
  margin-right:0 !important;
}
body.shop-page.feed-insta-ui .ig-feed-header{
  flex:0 0 auto !important;
  position:relative !important;
  top:auto !important;
  z-index:110 !important;
  margin:0 !important;
  border-bottom:1px solid var(--feed-post-divider, rgba(177,188,206,.22));
}
body.shop-page.feed-insta-ui .shop-page-shell{
  flex:1 1 auto !important;
  min-height:0 !important;
  overflow:hidden !important;
  display:flex !important;
  flex-direction:column !important;
  margin-top:0 !important;
  padding-bottom:0 !important;
}
body.shop-page.feed-insta-ui .shop-page-head-mobile{
  flex:0 0 auto !important;
}
body.shop-page.feed-insta-ui .shop-brand-banner{
  flex:0 0 auto !important;
}
body.shop-page.feed-insta-ui .shop-page-scroll{
  flex:1 1 auto !important;
  min-height:0 !important;
  overflow-y:auto !important;
  overflow-x:hidden !important;
  -webkit-overflow-scrolling:touch;
  overscroll-behavior:contain;
  padding-bottom:24px;
}
@media (min-width:1025px){
  body.shop-page.feed-insta-ui{
    --shop-left-rail-head-top:96px;
    --shop-left-rail-head-height:72px;
    --feed-left-rail-top:calc(var(--shop-left-rail-head-top) + var(--shop-left-rail-head-height));
    /* sh-mainpanel already clears the icon rail; only offset the left nav */
    --shop-left-chrome:calc(40px + 236px);
  }
  body.shop-page.feed-insta-ui .feed-left-rail-page-head{
    position:fixed;
    left:calc(var(--feedRailW, 84px) + 40px);
    top:var(--shop-left-rail-head-top);
    width:236px;
    z-index:95;
    box-sizing:border-box;
    background:var(--shop-surface, var(--msb-palette-bg, var(--feed-page-bg, var(--feed-topbar-bg, #f5f7fb))));
    color:var(--shop-text, var(--msb-palette-text, inherit));
    padding:0 12px 6px;
    margin:10px 0 0;
  }
  body.shop-page.feed-insta-ui .feed-left-rail{
    top:var(--feed-left-rail-top);
    padding-top:0;
  }
  body.shop-page.feed-insta-ui .shop-brand-nav{
    margin:0 0 8px;
    padding:2px 0 4px;
    border-top:0;
  }
  body.shop-page.feed-insta-ui .shop-page-shell{
    padding-left:calc(var(--shop-left-chrome) + 16px);
    padding-right:24px;
    box-sizing:border-box;
    width:100%;
    max-width:100%;
  }
  body.shop-page.feed-insta-ui .shop-market-grid{
    margin-left:0;
    margin-right:0;
    max-width:100%;
    width:100%;
  }
}
@media (max-width:1024px){
  body.shop-page.feed-insta-ui .shop-page-shell{
    padding-left:calc(var(--feedRailW, 84px) + 12px);
    padding-right:12px;
    box-sizing:border-box;
  }
}

/* product_detail.php, my_orders.php — fixed feed header, scrollable main body */
html:has(body.product-detail-page.feed-insta-ui),
html:has(body.my-orders-page.feed-insta-ui),
body.product-detail-page.feed-insta-ui,
body.my-orders-page.feed-insta-ui{
  overflow:hidden !important;
  height:100vh !important;
  max-height:100vh !important;
}
body.product-detail-page.feed-insta-ui .sh-mainpanel,
body.my-orders-page.feed-insta-ui .sh-mainpanel{
  height:100vh !important;
  max-height:100vh !important;
  overflow:hidden !important;
  display:flex !important;
  flex-direction:column !important;
  min-height:0 !important;
}
body.product-detail-page.feed-insta-ui .sh-pagebody,
body.my-orders-page.feed-insta-ui .sh-pagebody{
  flex:1 1 auto !important;
  min-height:0 !important;
  overflow:hidden !important;
  display:flex !important;
  flex-direction:column !important;
  padding-bottom:0 !important;
  margin-right:0 !important;
}
body.product-detail-page.feed-insta-ui .ig-feed-header,
body.my-orders-page.feed-insta-ui .ig-feed-header{
  flex:0 0 auto !important;
  position:relative !important;
  top:auto !important;
  z-index:110 !important;
  margin:0 !important;
  border-bottom:1px solid var(--feed-post-divider, rgba(177,188,206,.22));
}
body.product-detail-page.feed-insta-ui .shop-page-shell,
body.my-orders-page.feed-insta-ui .shop-page-shell{
  flex:1 1 auto !important;
  min-height:0 !important;
  overflow:hidden !important;
  display:flex !important;
  flex-direction:column !important;
  margin-top:0 !important;
  padding-bottom:0 !important;
}
body.my-orders-page.feed-insta-ui .shop-page-shell{
  flex:1 1 auto !important;
  min-height:0 !important;
  overflow:hidden !important;
  display:flex !important;
  flex-direction:column !important;
  margin-top:0 !important;
  padding-bottom:0 !important;
}
body.my-orders-page.feed-insta-ui .shop-page-head-mobile{
  flex:0 0 auto !important;
}
body.my-orders-page.feed-insta-ui .orders-page-intro{
  flex:0 0 auto !important;
  padding-bottom:12px;
}
body.my-orders-page.feed-insta-ui .orders-page-scroll{
  flex:1 1 auto !important;
  min-height:0 !important;
  overflow-y:auto !important;
  overflow-x:hidden !important;
  -webkit-overflow-scrolling:touch;
  padding-bottom:12px;
}
body.my-orders-page.feed-insta-ui .orders-page-footer{
  flex:0 0 auto !important;
  margin-top:auto !important;
}
body.product-detail-page.feed-insta-ui .shop-page-head-mobile{
  flex:0 0 auto !important;
}
body.product-detail-page.feed-insta-ui .pd-page{
  flex:1 1 auto !important;
  min-height:0 !important;
  overflow:hidden !important;
  margin-top: 20px;
}
body.product-detail-page.feed-insta-ui .pd-info-col{
  min-height:0 !important;
  overflow:hidden !important;
}
body.product-detail-page.feed-insta-ui .pd-tab-scroll{
  flex:1 1 auto !important;
  min-height:0 !important;
  overflow-y:auto !important;
  overflow-x:hidden !important;
  -webkit-overflow-scrolling:touch;
}
body.product-detail-page.feed-insta-ui .pd-info-foot{
  flex:0 0 auto !important;
}
@media (max-width:1024px){
  body.product-detail-page.feed-insta-ui .shop-page-shell,
  body.my-orders-page.feed-insta-ui .shop-page-shell{
    padding-left:calc(var(--feedRailW, 84px) + 12px);
    padding-right:12px;
  }
}

/* cart.php — fixed feed header + cart intro; scrollable line items only */
html:has(body.cart-page.feed-insta-ui),
body.cart-page.feed-insta-ui{
  overflow:hidden !important;
  height:100vh !important;
  max-height:100vh !important;
}
body.cart-page.feed-insta-ui .sh-mainpanel{
  height:100vh !important;
  max-height:100vh !important;
  overflow:hidden !important;
  display:flex !important;
  flex-direction:column !important;
  min-height:0 !important;
}
body.cart-page.feed-insta-ui .sh-pagebody{
  flex:1 1 auto !important;
  min-height:0 !important;
  overflow:hidden !important;
  display:flex !important;
  flex-direction:column !important;
  padding-bottom:0 !important;
  margin-right:0 !important;
}
body.cart-page.feed-insta-ui .ig-feed-header{
  flex:0 0 auto !important;
  position:relative !important;
  top:auto !important;
  z-index:110 !important;
  margin:0 !important;
  border-bottom:1px solid var(--feed-post-divider, rgba(177,188,206,.22));
}
body.cart-page.feed-insta-ui .shop-page-shell{
  flex:1 1 auto !important;
  min-height:0 !important;
  overflow:hidden !important;
  display:flex !important;
  flex-direction:column !important;
  margin-top:0 !important;
  padding-bottom:0 !important;
}
body.cart-page.feed-insta-ui .shop-page-head-mobile{
  flex:0 0 auto !important;
}
body.cart-page.feed-insta-ui .cart-page-intro{
  flex:0 0 auto !important;
  padding-bottom:12px;
}
body.cart-page.feed-insta-ui .cart-page-scroll{
  flex:1 1 auto !important;
  min-height:0 !important;
  overflow-y:auto !important;
  overflow-x:hidden !important;
  -webkit-overflow-scrolling:touch;
  padding-bottom:12px;
}
body.cart-page.feed-insta-ui .cart-page-footer{
  flex:0 0 auto !important;
  margin-top:auto !important;
}
@media (max-width:1024px){
  body.cart-page.feed-insta-ui .shop-page-shell{
    padding-left:calc(var(--feedRailW, 84px) + 12px);
    padding-right:12px;
  }
}
