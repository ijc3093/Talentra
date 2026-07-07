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
body.shop-page.feed-insta-ui .sh-pagebody{
  padding-top:0;
}
@media (min-width:1025px){
  body.shop-page.feed-insta-ui{
    --shop-left-chrome:calc(var(--feedRailW, 84px) + 40px + 236px);
  }
  body.shop-page.feed-insta-ui .shop-page-shell{
    padding-left:calc(var(--shop-left-chrome) + 24px);
    padding-right:24px;
    box-sizing:border-box;
    width:100%;
    max-width:100%;
  }
  body.shop-page.feed-insta-ui .shop-market-grid{
    margin-left:0;
    margin-right:auto;
    max-width:min(980px, calc(100vw - var(--shop-left-chrome) - 48px));
  }
}
@media (max-width:1024px){
  body.shop-page.feed-insta-ui .shop-page-shell{
    padding-left:calc(var(--feedRailW, 84px) + 12px);
    padding-right:12px;
    box-sizing:border-box;
  }
}
