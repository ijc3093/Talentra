<?php
declare(strict_types=1);
?>
/* Shared discover tabs: feed.php and public.php must render identically. */
.feed-discover-tabs{
  display:flex !important;
  align-items:stretch !important;
  width:100% !important;
  height:36px !important;
  min-height:36px !important;
  margin-top:8px !important;
  padding:0 !important;
  overflow-x:auto !important;
  overflow-y:hidden !important;
  scrollbar-width:none !important;
  box-sizing:border-box !important;
  view-transition-name:none !important;
  transition:none !important;
  animation:none !important;
  contain:layout !important;
}
.feed-discover-tabs::-webkit-scrollbar{
  display:none !important;
}
.feed-discover-tab,
.feed-discover-tab.is-active{
  position:relative !important;
  flex:1 0 auto !important;
  min-width:max-content !important;
  height:28px !important;
  margin:0 !important;
  padding:6px 10px 10px !important;
  border:0 !important;
  box-sizing:border-box !important;
  color:var(--feed-control-placeholder, var(--public-muted, #667085)) !important;
  font-family:"Roboto","Helvetica Neue",Arial,sans-serif !important;
  font-size:13px !important;
  font-weight:400 !important;
  font-style:normal !important;
  line-height:1.2 !important;
  letter-spacing:normal !important;
  font-kerning:auto !important;
  font-synthesis:none !important;
  text-align:center !important;
  text-decoration:none !important;
  white-space:nowrap !important;
  transition:none !important;
  animation:none !important;
  transform:none !important;
}
.feed-discover-tab:hover,
.feed-discover-tab:focus{
  color:var(--feed-topbar-text, var(--public-text, #0d0d0d)) !important;
  background:rgba(127,127,127,.07) !important;
  text-decoration:none !important;
  outline:none !important;
}
.feed-discover-tab.is-active{
  color:var(--feed-topbar-text, var(--public-text, #0d0d0d)) !important;
}
.feed-discover-tab::after{
  content:none !important;
}
.feed-discover-tab.is-active::after{
  content:"" !important;
  position:absolute !important;
  left:50% !important;
  bottom:0 !important;
  width:40px !important;
  max-width:70% !important;
  height:3px !important;
  border-radius:999px !important;
  background:#1d9bf0 !important;
  transform:translateX(-50%) !important;
  transition:none !important;
  animation:none !important;
}
