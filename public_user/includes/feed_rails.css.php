<?php
declare(strict_types=1);
?>
.feed-left-rail,
.feed-right-rail{display:none;}
body.feed-insta-ui .feed-left-nav-ic,
body.feed-insta-ui .feed-right-nav-ic{
  flex:0 0 20px;
  width:20px;
  height:20px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  color:#0d0d0d;
}
body.feed-insta-ui .feed-left-nav-ic svg,
body.feed-insta-ui .feed-right-nav-ic svg{
  display:block;
  width:18px;
  height:18px;
  max-width:18px;
  max-height:18px;
  stroke:currentColor;
  fill:none;
  stroke-width:1.75;
  stroke-linecap:round;
  stroke-linejoin:round;
}
@media (min-width:1025px){
  body.feed-insta-ui{
    --feed-left-nav-box-h:min(340px, calc(100vh - 280px));
  }
  body.feed-insta-ui .feed-left-rail{
    display:flex;
    flex-direction:column;
    position:fixed;
    left:calc(var(--feedRailW, 84px) + 40px);
    top:var(--feed-left-rail-top, 220px);
    width:236px;
    height:var(--feed-left-nav-box-h);
    max-height:var(--feed-left-nav-box-h);
    overflow:hidden;
    z-index:90;
    padding:4px 0 8px;
    box-sizing:border-box;
  }
  body.feed-insta-ui .feed-left-nav{
    display:flex;
    flex-direction:column;
    gap:2px;
    flex:1 1 auto;
    min-height:0;
    height:100%;
    max-height:100%;
    overflow-y:auto;
    overflow-x:hidden;
    padding:10px 2px 0 0;
    -webkit-overflow-scrolling:touch;
    overscroll-behavior:contain;
    touch-action:pan-y;
    scrollbar-width:thin;
    scrollbar-color:rgba(0,0,0,.18) transparent;
  }
  body.feed-insta-ui .feed-left-nav::-webkit-scrollbar{width:5px;}
  body.feed-insta-ui .feed-left-nav::-webkit-scrollbar-thumb{
    background:rgba(0,0,0,.18);
    border-radius:999px;
  }
  body.feed-insta-ui .feed-left-nav-item{
    display:flex;
    align-items:center;
    gap:12px;
    min-height:42px;
    padding:8px 12px;
    border-radius:10px;
    color:#0d0d0d;
    font-size:14px;
    font-weight:500;
    line-height:1.2;
    text-decoration:none;
    transition:background .15s ease,color .15s ease;
    box-sizing:border-box;
  }
  body.feed-insta-ui .feed-left-nav-item:hover,
  body.feed-insta-ui .feed-left-nav-item:focus{
    background:var(--msb-palette-nav-hover, #d0d8e4);
    color:var(--msb-palette-text-on-nav-hover, #0a0a0a);
    box-shadow:inset 0 0 0 1px rgba(15,23,42,.14);
    text-decoration:none;
    outline:none;
  }
  body.feed-insta-ui .feed-left-nav-item.is-active{
    background:#eef2f7;
    color:#0f172a;
    font-weight:600;
  }
  body.feed-insta-ui .feed-left-nav-label{
    flex:1 1 auto;
    min-width:0;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }
  body.feed-insta-ui .feed-left-nav-badge{
    flex:0 0 auto;
    margin-left:8px;
    padding:3px 8px;
    border-radius:999px;
    background:#f3f4f6;
    color:#6b7280;
    font-size:10px;
    font-weight:700;
    letter-spacing:.04em;
    line-height:1;
  }
  body.feed-insta-ui .feed-left-nav-item-under-public{
    margin-left:12px;
    padding-left:20px;
    min-height:38px;
    font-size:13px;
  }
  body.feed-insta-ui .feed-left-nav-item-company .feed-left-nav-label,
  body.feed-insta-ui .feed-left-nav-item-publisher .feed-left-nav-label{
    font-weight:600;
  }
  body.feed-insta-ui .feed-right-rail{
    display:block;
    position:fixed;
    right:24px;
    top:250px;
    width:248px;
    z-index:90;
    padding:0;
    box-sizing:border-box;
  }
  body.feed-insta-ui .feed-right-nav{
    display:flex;
    flex-direction:column;
    gap:2px;
    margin:0;
    padding:0;
  }
  body.feed-insta-ui .feed-right-nav-item{
    display:flex;
    align-items:center;
    gap:12px;
    min-height:42px;
    padding:8px 12px;
    border-radius:10px;
    color:#0d0d0d;
    font-size:14px;
    font-weight:500;
    line-height:1.2;
    text-decoration:none;
    transition:background .15s ease,color .15s ease;
    box-sizing:border-box;
  }
  body.feed-insta-ui .feed-right-nav-item.is-active{
    background:#f3f4f6;
    font-weight:700;
  }
  body.feed-insta-ui .feed-right-nav-item:hover,
  body.feed-insta-ui .feed-right-nav-item:focus{
    background:#f5f5f5;
    color:#000;
    text-decoration:none;
    outline:none;
  }
  body.feed-insta-ui .feed-right-nav-label{
    flex:1 1 auto;
    min-width:0;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }
  body.feed-insta-ui .feed-right-nav-badge{
    flex:0 0 auto;
    margin-left:8px;
    padding:3px 8px;
    border-radius:999px;
    background:#f3f4f6;
    color:#6b7280;
    font-size:10px;
    font-weight:700;
    letter-spacing:.04em;
    line-height:1;
  }
}
