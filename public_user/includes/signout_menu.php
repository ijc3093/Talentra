<?php
if (empty($GLOBALS['msb_signout_menu_css'])) {
  $GLOBALS['msb_signout_menu_css'] = true;
?>
<style id="msb-signout-menu-css">
.msb-signout-group{
  display:flex;
  flex-direction:column;
  width:100%;
}
.msb-signout-toggle{
  display:flex;
  align-items:center;
  gap:10px;
  width:100%;
  border:0;
  background:transparent;
  color:inherit;
  font:inherit;
  text-align:left;
  cursor:pointer;
  padding:0;
}
.msb-signout-toggle .icon,
.msb-signout-sub a .icon{width:18px;min-width:18px;text-align:center;}
.msb-signout-chevron{
  margin-left:auto;
  font-size:12px;
  opacity:.75;
  transform:rotate(0deg);
  transition:transform .18s ease;
}
.msb-signout-group.is-open .msb-signout-chevron{transform:rotate(180deg);}
.msb-signout-sub{
  display:none;
  order:-1;
  margin:0 0 8px;
  padding:6px 0;
  border-radius:12px;
  background:var(--msb-palette-surface, var(--msb-palette-hover-bg, rgba(148,163,184,.12)));
  border:1px solid var(--msb-palette-border, rgba(148,163,184,.22));
  overflow:hidden;
}
.msb-signout-group.is-open .msb-signout-sub{display:block;}
.msb-signout-sub a{
  display:flex;
  align-items:center;
  gap:12px;
  width:100%;
  min-height:40px;
  padding:8px 12px;
  color:inherit;
  text-decoration:none;
  font-size:15px;
  font-weight:400;
}
.msb-signout-sub a:hover{background:var(--msb-palette-hover-bg, rgba(255,255,255,.08));}
.bestprofile-nav .msb-signout-toggle,
.tt-profile-nav .msb-signout-toggle{
  min-height:34px;
  padding:8px 12px;
  color:inherit;
  font-weight:500;
  font-size:13px;
}
.bestprofile-nav .msb-signout-toggle:hover,
.tt-profile-nav .msb-signout-toggle:hover{
  background:var(--msb-dd-hover, rgba(255,255,255,.06));
}
.bestprofile-nav .msb-signout-sub,
.tt-profile-nav .msb-signout-sub{
  margin:0 8px 8px;
}
.tt-profile-nav .msb-signout-group{width:100%;}
.feed-left-rail-footer .msb-signout-group{width:100%;}
.feed-left-rail-footer .msb-signout-toggle{padding:0;color:inherit;}
.feed-left-rail-footer .msb-signout-sub{margin:0 8px 8px;}
</style>
<script>
(function(){
  if (window.__msbSignoutMenuBound) return;
  window.__msbSignoutMenuBound = true;
  document.addEventListener('click', function(e){
    var toggle = e.target && e.target.closest ? e.target.closest('.msb-signout-toggle') : null;
    if (toggle) {
      e.preventDefault();
      e.stopPropagation();
      var group = toggle.closest('.msb-signout-group');
      if (!group) return;
      var open = group.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      return;
    }
    document.querySelectorAll('.msb-signout-group.is-open').forEach(function(el){
      if (e.target && el.contains(e.target)) return;
      el.classList.remove('is-open');
      var btn = el.querySelector('.msb-signout-toggle');
      if (btn) btn.setAttribute('aria-expanded', 'false');
    });
  }, true);
})();
</script>
<?php
}

if (!function_exists('msb_signout_menu_inner_html')) {
  function msb_signout_menu_inner_html(): string {
    $t = static function (string $s): string {
      return htmlspecialchars(function_exists('app_t') ? app_t($s) : $s, ENT_QUOTES, 'UTF-8');
    };
    return
      '<a href="logout.php" class="js-signout-confirm"><i class="icon ion-android-exit"></i> ' . $t('Logout') . '</a>'
      . '<a href="switch_accounts.php" class="js-open-switch-accounts"><i class="icon ion-loop"></i> ' . $t('Switch accounts') . '</a>'
      . '<a href="index.php?add_account=1"><i class="icon ion-person-add"></i> ' . $t('Add account') . '</a>';
  }
}

if (!function_exists('msb_render_signout_group')) {
  function msb_render_signout_group(string $variant = 'nav'): void {
    $t = static function (string $s): string {
      return htmlspecialchars(function_exists('app_t') ? app_t($s) : $s, ENT_QUOTES, 'UTF-8');
    };
    $inner = msb_signout_menu_inner_html();
    if ($variant === 'rail') {
      echo '<div class="msb-signout-group">'
        . '<div class="msb-signout-sub">' . $inner . '</div>'
        . '<button type="button" class="feed-left-nav-item msb-signout-toggle" aria-expanded="false">'
        . '<span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 7V5a2 2 0 0 1 2-2h7v18h-7a2 2 0 0 1-2-2v-2"/><path d="M15 12H3"/><path d="M6 9l-3 3 3 3"/></svg></span>'
        . '<span class="feed-left-nav-label">' . $t('Sign Out') . '</span>'
        . '<span class="msb-signout-chevron" aria-hidden="true">▾</span>'
        . '</button>'
        . '</div>';
      return;
    }
    echo '<div class="msb-signout-group">'
      . '<div class="msb-signout-sub">' . $inner . '</div>'
      . '<button type="button" class="msb-signout-toggle" aria-expanded="false">'
      . '<i class="icon ion-android-exit"></i> ' . $t('Sign Out')
      . '<span class="msb-signout-chevron" aria-hidden="true">▾</span>'
      . '</button>'
      . '</div>';
  }
}
