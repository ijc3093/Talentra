(function(){
  'use strict';

  var PALETTE_STYLE_ID = 'msb-appearance-palette-style';

  function themeUserId(){
    var uid = parseInt(String(window.__MSB_THEME_USER_ID || '0'), 10);
    return uid > 0 ? uid : 0;
  }

  function scopedKey(base){
    var uid = themeUserId();
    return uid > 0 ? (base + '_u_' + uid) : base;
  }

  function normalizeManualMode(mode){
    return (mode === 'light' || mode === 'dark') ? mode : 'dark';
  }

  function normalizeAppearanceMode(mode){
    mode = String(mode || '').toLowerCase().trim();
    if (!mode) return 'system';
    if (mode === 'system' || mode === 'light' || mode === 'dark') return mode;
    if (window.__MSB_APPEARANCE_PALETTES && window.__MSB_APPEARANCE_PALETTES[mode]) {
      return mode;
    }
    return 'system';
  }

  function localStorageAllowed(){
    if (window.__MSB_THEME_DISABLE_LOCAL) return false;
    return themeUserId() <= 0;
  }

  function prefsFromServerDefaults(){
    var defaults = window.__MSB_THEME_DEFAULTS || {};
    return {
      autoEnabled: !!defaults.autoEnabled,
      manualMode: normalizeManualMode(defaults.manualMode),
      appearanceMode: normalizeAppearanceMode(defaults.appearanceMode || window.__MSB_THEME_DB_MODE || 'system')
    };
  }

  function purgeLegacyGlobalThemeKeys(){
    try {
      localStorage.removeItem('theme_mode');
      localStorage.removeItem('theme_auto_enabled');
      localStorage.removeItem('theme_manual_mode');
    } catch (e) {}
  }

  function purgeAllThemeStorageKeys(){
    purgeLegacyGlobalThemeKeys();
    try {
      var keysToRemove = [];
      var i;
      for (i = 0; i < localStorage.length; i++) {
        var key = localStorage.key(i);
        if (!key) continue;
        if (key === 'theme_mode'
          || key === 'theme_auto_enabled'
          || key === 'theme_manual_mode'
          || key.indexOf('theme_mode_u_') === 0
          || key.indexOf('theme_auto_enabled_u_') === 0
          || key.indexOf('theme_manual_mode_u_') === 0) {
          keysToRemove.push(key);
        }
      }
      keysToRemove.forEach(function(k){ localStorage.removeItem(k); });
    } catch (e) {}
  }

  function readMemoryPrefs(){
    var uid = themeUserId();
    if (uid > 0
      && window.__MSBThemePrefs
      && parseInt(String(window.__MSBThemePrefsUserId || '0'), 10) === uid) {
      return {
        autoEnabled: !!window.__MSBThemePrefs.autoEnabled,
        manualMode: normalizeManualMode(window.__MSBThemePrefs.manualMode),
        appearanceMode: normalizeAppearanceMode(window.__MSBThemePrefs.appearanceMode)
      };
    }
    return null;
  }

  function writeMemoryPrefs(prefs){
    var uid = themeUserId();
    if (uid <= 0) return prefs;
    window.__MSBThemePrefsUserId = uid;
    window.__MSBThemePrefs = {
      autoEnabled: !!prefs.autoEnabled,
      manualMode: normalizeManualMode(prefs.manualMode),
      appearanceMode: normalizeAppearanceMode(prefs.appearanceMode)
    };
    return window.__MSBThemePrefs;
  }

  function seedPrefsFromServer(){
    var uid = themeUserId();
    if (uid <= 0) {
      if (!localStorageAllowed()) purgeAllThemeStorageKeys();
      return prefsFromServerDefaults();
    }
    purgeAllThemeStorageKeys();
    return writeMemoryPrefs(prefsFromServerDefaults());
  }

  function readPrefs(){
    var uid = themeUserId();
    if (uid > 0) {
      var memory = readMemoryPrefs();
      return memory || seedPrefsFromServer();
    }

    var defaults = window.__MSB_THEME_DEFAULTS || {};
    if (!localStorageAllowed()) {
      purgeAllThemeStorageKeys();
      return prefsFromServerDefaults();
    }

    var legacy = 'auto';
    var autoRaw = null;
    var manual = '';
    try {
      legacy = localStorage.getItem('theme_mode') || 'auto';
      autoRaw = localStorage.getItem('theme_auto_enabled');
      manual = localStorage.getItem('theme_manual_mode') || '';
    } catch (e) {}

    if (manual !== 'light' && manual !== 'dark') {
      manual = normalizeManualMode(defaults.manualMode);
    }

    var autoEnabled;
    if (autoRaw === '1' || autoRaw === '0') {
      autoEnabled = (autoRaw === '1');
    } else {
      autoEnabled = !!defaults.autoEnabled;
    }

    return {
      autoEnabled: autoEnabled,
      manualMode: normalizeManualMode(manual),
      appearanceMode: normalizeAppearanceMode(defaults.appearanceMode || window.__MSB_THEME_DB_MODE || 'system')
    };
  }

  function writeScopedPrefs(prefs){
    var uid = themeUserId();
    if (uid > 0) return writeMemoryPrefs(prefs);
    if (!localStorageAllowed()) return prefs;
    try {
      localStorage.setItem('theme_auto_enabled', prefs.autoEnabled ? '1' : '0');
      localStorage.setItem('theme_manual_mode', prefs.manualMode);
      localStorage.setItem('theme_mode', prefs.autoEnabled ? 'auto' : (prefs.appearanceMode || prefs.manualMode));
    } catch (e) {}
    return prefs;
  }

  function prefersDark(){
    try {
      return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    } catch (e) {
      return false;
    }
  }

  function supportsPrefersColorScheme(){
    try {
      return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').media !== 'not all');
    } catch (e) {
      return false;
    }
  }

  function isNight(){
    var h = new Date().getHours();
    return (h >= 17 || h < 6);
  }

  /** Day/night auto follows clock time (5pm-6am), not OS appearance preference. */
  function shouldAutoDark(){
    return isNight();
  }

  function hexToRgb(hex){
    hex = String(hex || '').replace('#', '');
    if (hex.length !== 6) return null;
    return {
      r: parseInt(hex.slice(0, 2), 16),
      g: parseInt(hex.slice(2, 4), 16),
      b: parseInt(hex.slice(4, 6), 16)
    };
  }

  function rgbToHex(r, g, b){
    function clamp(n){ return Math.max(0, Math.min(255, Math.round(n))); }
    function part(n){
      var s = clamp(n).toString(16);
      return s.length < 2 ? '0' + s : s;
    }
    return '#' + part(r) + part(g) + part(b);
  }

  function mixHex(hex, mixHexColor, weight){
    var a = hexToRgb(hex);
    var b = hexToRgb(mixHexColor);
    if (!a || !b) return hex;
    var w = Math.max(0, Math.min(1, weight));
    return rgbToHex(
      a.r + (b.r - a.r) * w,
      a.g + (b.g - a.g) * w,
      a.b + (b.b - a.b) * w
    );
  }

  function appearanceDarkChrome(){
    return '#b1bcce';
  }

  function appearanceDarkBg(){
    if (window.__MSB_APPEARANCE_PALETTES
      && window.__MSB_APPEARANCE_PALETTES.dark
      && window.__MSB_APPEARANCE_PALETTES.dark.hex) {
      return window.__MSB_APPEARANCE_PALETTES.dark.hex;
    }
    return '#171d24';
  }

  function appearanceLightBg(){
    if (window.__MSB_APPEARANCE_PALETTES
      && window.__MSB_APPEARANCE_PALETTES.light
      && window.__MSB_APPEARANCE_PALETTES.light.hex) {
      return window.__MSB_APPEARANCE_PALETTES.light.hex;
    }
    return '#f5f7fb';
  }

  function paletteMeta(slug){
    slug = normalizeAppearanceMode(slug);
    if (slug === 'light') return { hex: '#f5f7fb', dark: false };
    if (slug === 'dark') return { hex: appearanceDarkBg(), dark: true };
    return (window.__MSB_APPEARANCE_PALETTES && window.__MSB_APPEARANCE_PALETTES[slug]) || null;
  }

  function relativeLuminance(hex){
    var rgb = hexToRgb(hex);
    if (!rgb) return 0;
    function channel(c){
      c = c / 255;
      return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
    }
    return 0.2126 * channel(rgb.r) + 0.7152 * channel(rgb.g) + 0.0722 * channel(rgb.b);
  }

  function contrastRatio(hex1, hex2){
    var l1 = relativeLuminance(hex1);
    var l2 = relativeLuminance(hex2);
    var lighter = Math.max(l1, l2);
    var darker = Math.min(l1, l2);
    return (lighter + 0.05) / (darker + 0.05);
  }

  function contrastText(hex){
    var dark = '#0f172a';
    var light = '#f8fafc';
    if (contrastRatio(hex, dark) >= contrastRatio(hex, light)) {
      return dark;
    }
    return light;
  }

  function ensureContrast(fg, bg, minRatio){
    minRatio = minRatio || 4.5;
    if (fg && contrastRatio(fg, bg) >= minRatio) {
      return fg;
    }
    var dark = '#0f172a';
    var light = '#f8fafc';
    if (contrastRatio(dark, bg) >= minRatio) return dark;
    if (contrastRatio(light, bg) >= minRatio) return light;
    return contrastRatio(bg, dark) >= contrastRatio(bg, light) ? dark : light;
  }

  function mutedOn(bg){
    var primary = ensureContrast(null, bg, 4.5);
    var lightPrimary = relativeLuminance(primary) > 0.5;
    var candidates = lightPrimary
      ? ['#1e293b', '#334155', '#475569', '#64748b']
      : ['#e2e8f0', '#cbd5e1', '#94a3b8', '#64748b'];
    var i;
    for (i = 0; i < candidates.length; i++) {
      if (contrastRatio(candidates[i], bg) >= 4.2) {
        return candidates[i];
      }
    }
    return primary;
  }

  function accentLinkOn(bg, accent){
    var mixes = [0, 0.2, 0.35, 0.5, 0.65];
    var i;
    for (i = 0; i < mixes.length; i++) {
      var darker = mixHex(accent, '#000000', mixes[i]);
      if (contrastRatio(darker, bg) >= 4.5) return darker;
    }
    for (i = 1; i <= 4; i++) {
      var lighter = mixHex(accent, '#ffffff', i * 0.12);
      if (contrastRatio(lighter, bg) >= 4.5) return lighter;
    }
    return ensureContrast(accent, bg, 4.5);
  }

  function hoverBgOn(bg, isDark){
    var hover = isDark ? mixHex(bg, '#ffffff', 0.12) : mixHex(bg, '#000000', 0.08);
    if (contrastRatio(bg, hover) < 1.08) {
      hover = isDark ? mixHex(bg, '#ffffff', 0.2) : mixHex(bg, '#000000', 0.14);
    }
    return hover;
  }

  function btnBgOn(accent, surface, isDark){
    if (contrastRatio(accent, surface) >= 3) {
      return accent;
    }
    return isDark ? mixHex(accent, '#ffffff', 0.18) : mixHex(accent, '#000000', 0.28);
  }

  function paletteForegroundColors(bg, isDark){
    var lum = relativeLuminance(bg);
    var useDarkFg = lum > 0.38 || (!isDark && lum > 0.28);
    if (isDark && lum < 0.22) {
      useDarkFg = false;
    }
    if (useDarkFg) {
      var hoverBg = hoverBgOn(bg, false);
      var hoverText = '#0a0a0a';
      return {
        mode: 'dark-fg',
        text: '#0a0a0a',
        muted: '#1f2937',
        soft: '#374151',
        icon: '#0a0a0a',
        link: '#111827',
        linkHover: '#0a0a0a',
        border: 'rgba(0,0,0,0.24)',
        borderStrong: 'rgba(0,0,0,0.36)',
        btnBg: '#111827',
        btnText: '#ffffff',
        btnHoverBg: '#000000',
        placeholder: '#374151',
        hoverBg: hoverBg,
        hoverText: hoverText,
        hoverIcon: hoverText,
        navHoverBg: mixHex(bg, '#000000', 0.16),
        navHoverText: hoverText,
        navHoverIcon: hoverText,
        navActiveBg: mixHex(bg, '#000000', 0.12),
        navActiveText: '#0a0a0a',
        navActiveIcon: '#0a0a0a'
      };
    }
    var darkHoverBg = mixHex(bg, '#ffffff', 0.2);
    var darkHoverText = ensureContrast(null, darkHoverBg, 4.5);
    var darkActiveBg = mixHex(bg, '#ffffff', 0.12);
    var darkActiveText = ensureContrast(null, darkActiveBg, 4.5);
    return {
      mode: 'light-fg',
      text: '#f8fafc',
      muted: '#cbd5e1',
      soft: '#94a3b8',
      icon: '#f8fafc',
      link: '#e2e8f0',
      linkHover: darkHoverText,
      border: 'rgba(255,255,255,0.14)',
      borderStrong: 'rgba(255,255,255,0.24)',
      btnBg: mixHex('#f8fafc', bg, 0.15),
      btnText: '#0a0a0a',
      btnHoverBg: mixHex('#ffffff', bg, 0.22),
      placeholder: '#94a3b8',
      hoverBg: darkHoverBg,
      hoverText: darkHoverText,
      hoverIcon: darkHoverText,
      navHoverBg: darkHoverBg,
      navHoverText: darkHoverText,
      navHoverIcon: darkHoverText,
      navActiveBg: darkActiveBg,
      navActiveText: darkActiveText,
      navActiveIcon: darkActiveText
    };
  }

  /** Tint icons/text/hover pills from the chosen palette (pink bg → dark pink ink, light pink hovers). */
  function paletteChromaticForeground(hex, bg, isDark){
    var base = paletteForegroundColors(bg, isDark);
    if (!hex) return base;
    var accent = String(hex);

    function inkDark(amount){
      return ensureContrast(mixHex(accent, '#000000', amount), bg, 4.5);
    }
    function inkMid(amount){
      return ensureContrast(mixHex(accent, '#000000', amount), bg, 4.2);
    }
    function inkLight(amount){
      return ensureContrast(mixHex(accent, '#ffffff', amount), bg, 4.5);
    }
    function surfaceTint(amount){
      return mixHex(bg, accent, amount);
    }

    if (base.mode === 'dark-fg') {
      var darkInk = inkDark(0.78);
      var midInk = inkDark(0.58);
      var softInk = inkMid(0.42);
      var navHover = mixHex(bg, '#000000', 0.16);
      var navActive = surfaceTint(0.26);
      var hoverBg = surfaceTint(0.12);
      var btnBg = inkDark(0.52);
      return {
        mode: base.mode,
        text: darkInk,
        muted: midInk,
        soft: softInk,
        icon: darkInk,
        link: inkDark(0.48),
        linkHover: darkInk,
        border: rgbaFromHex(accent, 0.22),
        borderStrong: rgbaFromHex(accent, 0.34),
        btnBg: btnBg,
        btnText: ensureContrast(null, btnBg, 4.5),
        btnHoverBg: inkDark(0.62),
        placeholder: midInk,
        hoverBg: hoverBg,
        hoverText: darkInk,
        hoverIcon: darkInk,
        navHoverBg: navHover,
        navHoverText: darkInk,
        navHoverIcon: darkInk,
        navActiveBg: navActive,
        navActiveText: darkInk,
        navActiveIcon: darkInk
      };
    }

    var lightInk = inkLight(0.82);
    var midLight = inkLight(0.68);
    var navHoverD = mixHex(bg, accent, 0.22);
    var navActiveD = mixHex(bg, accent, 0.14);
    var btnBgD = inkLight(0.5);
    return {
      mode: base.mode,
      text: lightInk,
      muted: midLight,
      soft: midLight,
      icon: lightInk,
      link: inkLight(0.62),
      linkHover: lightInk,
      border: rgbaFromHex(accent, 0.18),
      borderStrong: rgbaFromHex(accent, 0.28),
      btnBg: btnBgD,
      btnText: ensureContrast(null, btnBgD, 4.5),
      btnHoverBg: inkLight(0.58),
      placeholder: midLight,
      hoverBg: navHoverD,
      hoverText: lightInk,
      hoverIcon: lightInk,
      navHoverBg: navHoverD,
      navHoverText: lightInk,
      navHoverIcon: lightInk,
      navActiveBg: navActiveD,
      navActiveText: lightInk,
      navActiveIcon: lightInk
    };
  }

  function paletteIncomingBubbleColors(hex, bg, isDark, fg){
    if (!hex) {
      return {
        bg: isDark ? mixHex(bg, '#ffffff', 0.1) : '#f5f5f5',
        text: fg.text,
        meta: fg.muted,
        border: fg.border,
        replyBg: isDark ? 'rgba(255,255,255,.08)' : 'rgba(15,23,42,.04)'
      };
    }
    var accent = String(hex);
    if (fg.mode === 'dark-fg') {
      var bubbleBg = mixHex(mixHex('#ffffff', accent, 0.26), bg, 0.34);
      if (contrastRatio(bubbleBg, bg) < 1.12) {
        bubbleBg = mixHex(mixHex(bg, '#ffffff', 0.5), accent, 0.14);
      }
      var bubbleText = ensureContrast(mixHex(accent, '#000000', 0.58), bubbleBg, 4.5);
      var bubbleMeta = ensureContrast(mixHex(accent, '#000000', 0.34), bubbleBg, 3.2);
      return {
        bg: bubbleBg,
        text: bubbleText,
        meta: bubbleMeta,
        border: rgbaFromHex(accent, 0.16),
        replyBg: mixHex(bubbleBg, '#000000', 0.06)
      };
    }
    var bubbleBgD = mixHex(mixHex(bg, accent, 0.2), '#ffffff', 0.08);
    var bubbleTextD = ensureContrast(mixHex(accent, '#ffffff', 0.72), bubbleBgD, 4.5);
    var bubbleMetaD = ensureContrast(mixHex(accent, '#ffffff', 0.56), bubbleBgD, 3.2);
    return {
      bg: bubbleBgD,
      text: bubbleTextD,
      meta: bubbleMetaD,
      border: rgbaFromHex(accent, 0.22),
      replyBg: mixHex(bubbleBgD, '#ffffff', 0.1)
    };
  }

  var PALETTE_FG_SELECTORS = [
    'a', '.nav-link', '.feed-ig-btn', '.feed-ig-link', '.topicon-btn', '.dropdown-link',
    '.dropdown-item', '.dropdown-menu-link', '.bestchat-menu-item', '.dropdown-bestnoti-item',
    '.btn', '.btn-light', '.btn-white', '.btn-soft', '.btn-outline-secondary', '.btn-link',
    '.icon', 'i.icon', '.sh-icon-link', '.tt-iconbtn', '.iconbtn', '.ig-btn',
    '.messages-shell-action-btn', '.messages-shell-tab',
    '.sh-logo-text', '.ig-stories-brand', '.ig-stories-menu-btn', '.yt-brand', '.yt-icon-btn',
    '.search-btn', '.yt-mic-btn', '.action-btn', '.gear-control', '.form-control',
    'input', 'textarea', 'select', '.chat-name', '.peerTitle', '.messages-shell-title',
    '.feed-title', '.card-title', '.gear-card-title', '.gear-title', 'label', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    '.text-muted', 'small', '.small', '.feed-desc', '.gear-sub', '.gear-card-desc',
    'body.profile-page .gear-sidebar-title', 'body.profile-page .gear-detail-title',
    'body.profile-page .gear-detail-desc', 'body.profile-page .gear-nav-section-toggle',
    'body.profile-page .gear-nav-section-label', 'body.profile-page .gear-nav-item',
    'body.profile-page .gear-nav-item-meta', 'body.profile-page .gear-detail-control-label',
    'body.profile-page .gear-chip', 'body.profile-page .gear-tag', 'body.profile-page .gear-note',
    'body.profile-page .gear-detail-empty', 'body.profile-page .gear-upload-hint',
    'body.profile-page .gear-nav-section-chevron',
    '.tt-comments-head .title', '.tt-comments-head .count', '.tt-name', '.tt-text', '.tt-meta',
    '.tt-rm-title', '.tt-rm-sub', '.tt-rm-body', '.tt-menu-head .title',
    'body.profile-page .ig-username', 'body.profile-page .ig-stat', 'body.profile-page .ig-bio',
    'body.profile-page .ig-tab', 'body.profile-page .about-head .nm', 'body.profile-page .about-card .v',
    'body.profile-page .about-title', 'body.profile-page #profilePostsFeed .mf-name',
    'body.profile-page #profilePostsFeed .mf-title', 'body.profile-page #profilePostsFeed .mf-body',
    'body.profile-page .gear-save-state', 'span.muted', '.mf-num', '.pv-n', '.pv-views',
    '.ig-story-name', '.mf-music-title', '.mf-music-artist', '.nav-link > span',
    '.sh-icon-link > span', 'body.org-app .sidebar-meta > span'
  ].join(',\nhtml[data-msb-appearance] ');

  var PALETTE_SPAN_SURFACE_SELECTORS = [
    'body.profile-page .gear-tag', 'span.gear-tag', 'span.profile-account-badge',
    '.profile-account-badge', 'span.badge', '.badge', '.badge-light', 'span.feed-badge',
    '.feed-badge', 'span.stat-pill', '.stat-pill', 'span.commerce-pill', '.commerce-pill',
    'span.commerce-order-badge', '.commerce-order-badge', 'span.commerce-brand-card-badge',
    '.commerce-brand-card-badge', 'span.pv-pill', '.pv-pill', 'span.pv-likepill',
    '.pv-likepill', 'span.sidebar-pill', '.sidebar-pill', 'span.msg-pill', '.msg-pill',
    'span.msg-reaction-pill', '.msg-reaction-pill', 'span.live-modal-badge',
    '.live-modal-badge', 'span.sidebar-chip', '.sidebar-chip', 'span.ig-profile-love-count',
    '.ig-profile-love-count'
  ].join(',\nhtml[data-msb-appearance] ');

  var PALETTE_SPAN_ACCENT_SURFACE_SELECTORS = [
    'body.profile-page .gear-chip', 'span.gear-chip'
  ].join(',\nhtml[data-msb-appearance] ');

  var PALETTE_NAV_HOVER_SELECTORS = [
    '#ttNavLeftbar .nav-link:hover',
    'body #ttNavLeftbar .nav-link:hover',
    '.sh-sideleft-menu .nav-link:hover',
    '.sh-sideleft-menu .nav > .nav-item > .nav-link:hover',
    '.org-sideleft-scroll .nav > .nav-item > .nav-link:hover',
    '.sh-sideleft-menu .nav-sub .nav-link:hover',
    '.feed-left-nav-item:hover',
    '.feed-right-nav-item:hover',
    '.tt-menu-body .feed-left-nav-item:hover',
    '.feed-ig-btn:hover',
    '.feed-ig-btn:focus',
    '.feed-ig-link:hover',
    '.feed-ig-link:focus',
    '.ig-link:hover',
    '.bestprofile-nav li a:hover',
    '.right-sidebar a:hover',
    '.feed-sidebar a:hover',
    '.feed-sidebar .sidebar-item:hover'
  ].join(',\nhtml[data-msb-appearance] ');

  var PALETTE_NAV_ACTIVE_SELECTORS = [
    '#ttNavLeftbar .nav-link.active',
    'body #ttNavLeftbar .nav-link.active',
    '.sh-sideleft-menu .nav-link.active',
    '.sh-sideleft-menu .nav > .nav-item > .nav-link.active',
    '.org-sideleft-scroll .nav > .nav-item > .nav-link.active',
    '.feed-left-nav-item.is-active',
    '.feed-right-nav-item.is-active',
    '.tt-menu-body .feed-left-nav-item.is-active',
    '.feed-ig-btn.active',
    '.feed-ig-link.active',
    '.ig-link.active',
    '.bestprofile-nav li a.active',
    '.right-sidebar a.active',
    '.feed-sidebar a.active',
    '.feed-sidebar .sidebar-item.is-active'
  ].join(',\nhtml[data-msb-appearance] ');

  var PALETTE_NAV_REST_SELECTORS = [
    '#ttNavLeftbar .nav-link:not(:hover)',
    'body #ttNavLeftbar .nav-link:not(:hover)',
    '.sh-sideleft-menu .nav-link:not(:hover)',
    '.sh-sideleft-menu .nav > .nav-item > .nav-link:not(:hover)',
    '.org-sideleft-scroll .nav > .nav-item > .nav-link:not(:hover)',
    '.sh-sideleft-menu .nav-sub .nav-link:not(:hover)',
    '.feed-left-nav-item:not(:hover)',
    '.feed-right-nav-item:not(:hover)',
    '.tt-menu-body .feed-left-nav-item:not(:hover)',
    '.feed-ig-btn:not(:hover)',
    '.feed-ig-link:not(:hover)',
    '.ig-link:not(:hover)',
    '.bestprofile-nav li a:not(:hover)',
    '.right-sidebar a:not(:hover)',
    '.feed-sidebar a:not(:hover)',
    '.feed-sidebar .sidebar-item:not(:hover)'
  ].join(',\nhtml[data-msb-appearance] ');

  var PALETTE_ORG_FOCUS_RESET_SELECTORS = [
    '.sh-sideleft-menu .nav > .nav-item > .nav-link:focus:not(:hover)',
    '.org-sideleft-scroll .nav > .nav-item > .nav-link:focus:not(:hover)',
    '.sh-sideleft-menu .nav-sub .nav-link:focus:not(:hover)',
    '.feed-sidebar .sidebar-item:focus:not(:hover)'
  ].join(',\nhtml[data-msb-appearance] ');

  var PALETTE_NAV_CHILD_SUFFIXES = [
    '.icon', 'i.icon', '[class*="ion-"]', 'span',
    '.feed-left-nav-ic', '.feed-right-nav-ic', '.feed-left-nav-label', '.feed-right-nav-label',
    '.sidebar-subject', '.sidebar-meta', '.sidebar-chip'
  ];

  function paletteHoverChildSelectors(suffix){
    var bases = [
      '.nav-link:hover',
      '.topicon-btn:hover', '.dropdown-link:hover', '.dropdown-item:hover', '.dropdown-menu-link:hover',
      '.dropdown-bestchat-link:hover', '.dropdown-bestprofile-link:hover',
      '.bestchat-menu-item:hover', '.dropdown-bestnoti-item:hover',
      '.bestnoti-footer-nav li a:hover', '.bestchat-footer-nav li a:hover',
      '.btn:hover', '.btn-light:hover', '.btn-white:hover', '.btn-soft:hover',
      '.btn-outline-secondary:hover', '.btn-link:hover',
      '.messages-shell-action-btn:hover', '.messages-shell-tab:not(.active):hover',
      '.ig-stories-menu-btn:hover', '.yt-icon-btn:hover', '.search-btn:hover', '.yt-mic-btn:hover',
      '.gear-quick a:hover', '.action-btn:hover', '.sh-icon-link:hover', '.tt-iconbtn:hover',
      '.iconbtn:hover', '.ig-btn:hover', '.msb-reaction-picker-item:hover'
    ];
    return bases.map(function(base){ return base + ' ' + suffix; }).join(',\nhtml[data-msb-appearance] ');
  }

  var PALETTE_FG_HOVER_SELECTORS = [
    '.topicon-btn:hover', '.dropdown-link:hover', '.dropdown-item:hover', '.dropdown-menu-link:hover',
    '.dropdown-bestchat-link:hover', '.dropdown-bestprofile-link:hover',
    '.bestchat-menu-item:hover', '.dropdown-bestnoti-item:hover',
    '.bestnoti-footer-nav li a:hover', '.bestchat-footer-nav li a:hover',
    '.btn:hover', '.btn-light:hover', '.btn-white:hover', '.btn-soft:hover',
    '.btn-outline-secondary:hover', '.btn-link:hover',
    '.messages-shell-action-btn:hover', '.messages-shell-tab:not(.active):hover',
    '.ig-stories-menu-btn:hover', '.yt-icon-btn:hover', '.search-btn:hover', '.yt-mic-btn:hover',
    '.gear-quick a:hover', '.action-btn:hover', '.sh-icon-link:hover', '.tt-iconbtn:hover',
    '.iconbtn:hover', '.ig-btn:hover', '.msb-reaction-picker-item:hover'
  ].join(',\nhtml[data-msb-appearance] ');

  var PALETTE_FG_HOVER_EXCLUDE_SELECTORS = [
    'a.feed-ig-logo:hover', 'a.feed-ig-logo:focus',
    '.btn-primary:hover', '.btn-primary:focus',
    '.bg-primary:hover', '.messages-shell-tab.active:hover',
    '.mf-feed .mf-card .mf-head a:hover', '.mf-feed .mf-card .mf-head .mf-peer-link:hover',
    '.feed-insta-ui .ig-insta-card .ig-insta-header a:hover',
    '.feed-insta-ui .ig-insta-card .card-header a:hover',
    '.post.public-post-card .post-header a:hover', '.post.public-post-card .post-author-link:hover',
    '.post.public-post-card .standard-media-topbar a:hover', '.post.public-post-card .standard-media-author:hover',
    '.post.public-post-card .standard-text-topbar a:hover'
  ].join(',\nhtml[data-msb-appearance] ');

  var PALETTE_POST_CARD_HEADER_HOVER_SELECTORS = [
    'body .mf-feed .mf-card .mf-head a:hover', 'body .mf-feed .mf-card .mf-head .mf-peer-link:hover',
    'body.feed-insta-ui .ig-insta-card .ig-insta-header a:hover',
    'body.feed-insta-ui .ig-insta-card .card-header a:hover',
    'body.feed-insta-ui .ig-insta-card .ig-insta-header .ig-insta-username a:hover',
    'body .post.public-post-card .post-header a:hover', 'body .post.public-post-card .post-author-link:hover',
    'body .post.public-post-card .standard-media-topbar a:hover', 'body .post.public-post-card .standard-media-author:hover',
    'body .post.public-post-card .standard-text-topbar a:hover'
  ].join(',\nhtml[data-msb-appearance] ');

  var PALETTE_POST_CARD_HEADER_HOVER_CHILD_SELECTORS = [
    'body .mf-feed .mf-card .mf-head a:hover .mf-name',
    'body .mf-feed .mf-card .mf-head a:hover .mf-time',
    'body.feed-insta-ui .ig-insta-card .ig-insta-header a:hover',
    'body.feed-insta-ui .ig-insta-card .ig-insta-header .ig-insta-username a:hover',
    'body .post.public-post-card .post-header a:hover .name',
    'body .post.public-post-card .post-author-link:hover .name',
    'body .post.public-post-card .post-header a:hover .time',
    'body .post.public-post-card .standard-media-topbar a:hover .standard-media-name',
    'body .post.public-post-card .standard-media-topbar a:hover .standard-media-time',
    'body .post.public-post-card .standard-text-topbar a:hover .standard-text-name'
  ].join(',\nhtml[data-msb-appearance] ');

  function paletteNavChildSelectors(parentSelectors, suffix){
    var parents = parentSelectors.split(',\nhtml[data-msb-appearance] ');
    var out = [];
    var i;
    var j;
    for (i = 0; i < parents.length; i++) {
      out.push(parents[i] + ' ' + suffix);
    }
    return out.join(',\nhtml[data-msb-appearance] ');
  }

  function paletteNavChildSelectorBlock(parentSelectors, cssProp, cssValue){
    var lines = [];
    var i;
    for (i = 0; i < PALETTE_NAV_CHILD_SUFFIXES.length; i++) {
      lines.push('html[data-msb-appearance] ' + paletteNavChildSelectors(parentSelectors, PALETTE_NAV_CHILD_SUFFIXES[i]));
    }
    return lines.join(',\n') + ' {\n  ' + cssProp + ': ' + cssValue + ' !important;\n  stroke: currentColor !important;\n}\n';
  }

  function paletteStylesheetHref(){
    var scripts = document.getElementsByTagName('script');
    var i;
    for (i = 0; i < scripts.length; i++) {
      var src = scripts[i].src || '';
      if (src.indexOf('theme-bootstrap.js') !== -1) {
        return src.replace(/\/js\/theme-bootstrap\.js.*$/, '/css/appearance-palette.css?v=37');
      }
    }
    return './css/appearance-palette.css?v=37';
  }

  function ensurePaletteStylesheet(){
    var link = document.getElementById(PALETTE_STYLE_ID);
    if (!link) {
      link = document.createElement('link');
      link.id = PALETTE_STYLE_ID;
      link.rel = 'stylesheet';
      link.href = paletteStylesheetHref();
      document.head.appendChild(link);
    }
    function moveToEnd(){
      if (link.parentNode) {
        document.head.appendChild(link);
      }
    }
    moveToEnd();
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', moveToEnd);
    }
    window.addEventListener('load', moveToEnd);
  }

  function clearPaletteStylesheet(){
    var link = document.getElementById(PALETTE_STYLE_ID);
    if (link && link.parentNode) {
      link.parentNode.removeChild(link);
    }
  }

  function paletteUnifiedBackground(hex, isDark){
    if (isDark) {
      return mixHex(hex, '#000000', 0.82);
    }
    var rgb = hexToRgb(hex);
    if (!rgb) {
      return mixHex(hex, '#ffffff', 0.9);
    }
    var max = Math.max(rgb.r, rgb.g, rgb.b);
    var min = Math.min(rgb.r, rgb.g, rgb.b);
    var chroma = max - min;
    var weight;
    if (chroma < 30) {
      weight = 0.55;
    } else if (chroma > 50 && max > 100) {
      weight = 0.9;
    } else {
      weight = 0.78;
    }
    return mixHex(hex, '#ffffff', weight);
  }

  function palettePanelSurface(bg, isDark){
    if (isDark) {
      return mixHex(bg, '#ffffff', 0.1);
    }
    return mixHex(bg, '#ffffff', 0.75);
  }

  function paletteActionAccent(hex, bg, isDark){
    var accent = accentLinkOn(bg, hex);
    if (contrastRatio(accent, bg) < 3) {
      accent = isDark ? mixHex(hex, '#ffffff', 0.55) : mixHex(hex, '#000000', 0.55);
      if (contrastRatio(accent, bg) < 3) {
        accent = ensureContrast(hex, bg, 4.5);
      }
    }
    return accent;
  }

  var PALETTE_PAINT_STYLE_ID = 'msb-appearance-palette-inline';
  var PALETTE_PAINT_SELECTORS = [
    'body', '.sh-mainpanel', '.sh-pagebody', '.sh-headpanel', '.sh-logopanel',
    '.sh-sideleft-menu', '#ttNavLeftbar', '.feed-ig-rail', '.feed-sidebar', '.right-sidebar',
    '.feed-main', '.app-main', '.card', '.card-body', '.card-header', '.panel', '.panel-body',
    '.ig-feed-header', '.ig-stories-wrap', '.ig-stories-bar', '.hero', '.sh-pagetitle',
    '.modal-content', '.modal-body', '.modal-header', '.modal-footer',
    '.dropdown-menu', '.dropdown-bestnoti-menu', '.dropdown-bestchat-menu',
    '.dropdown-bestnoti-list', '.dropdown-bestchat-list', '.dropdown-bestnoti-empty',
    '.dropdown-bestchat-empty', '.bestnoti-footer-nav', '.bestchat-footer-nav',
    '.messages-shell', '.messages-shell-head', '.messages-shell-search', '.chat-card',
    '.chat-card-right', '.chat-card-left', '.chat-card-body', '.mailbox-body',
    '#chatBox', '.chat-list', '.chat-topbar', '.chat-info-panel', '.composer', '.composer-bar',
    '.conv-history', '.chat-mode-empty', '.group-panel-card', '.group-list-empty',
    '.group-create-dialog', '.gif-picker', '#emojiMenu',
    '#videoCallOverlay', '.vcall-shell', '.vcall-header', '.vcall-stage-wrap', '.vcall-stage',
    '.vcall-sidebar', '.vcall-controls', '.vcall-incoming',
    '.signbox', '.signbox-body', '.requests-panel', '.requests-scroll', '.requests-fixed',
    '.studio-main', '.studio-frame', '.studio-shell', '.studio-rail', '.step', '.source-card',
    '.software-panel', '.software-mode-card', '.info-card', '.history-card', '.chat-card h4',
    '.create-post-dialog', '.create-post-body', '.create-post-frame',
    '.empty', '.gear-card', '.gear-wrap', '.gear-hero', '.gear-quick a', '.feed-card',
    'body.profile-page', 'body.profile-page .sh-mainpanel', 'body.profile-page .sh-pagebody',
    'body.profile-page .ig-wrap', 'body.profile-page .ig-profile-shell',
    'body.profile-page .ig-profile-head', 'body.profile-page .ig-profile-scroll',
    'body.profile-page .profile-panel', 'body.profile-page .coming-wrap',
    'body.profile-page .ig-tabs', 'body.profile-page .ig-highlights',
    'body.profile-page .ig-gallery-search input', 'body.profile-page .ig-gallery-filter select',
    'body.profile-page .mf-feed-empty',
    'body.profile-page #profilePostsFeed .mf-card', 'body.profile-page .about-head',
    'body.profile-page .about-card', 'body.profile-page .about-note', 'body.profile-page .coming-card',
    'body.profile-page .gear-wrap', 'body.profile-page .gear-shell', 'body.profile-page .gear-sidebar',
    'body.profile-page .gear-sidebar-head', 'body.profile-page .gear-main', 'body.profile-page .gear-nav',
    'body.profile-page .gear-nav-section-toggle', 'body.profile-page .gear-nav-item',
    'body.profile-page .gear-detail-panel', 'body.profile-page .gear-detail-head', 'body.profile-page .gear-detail-body',
    'body.profile-page .gear-detail-control', 'body.profile-page .gear-detail-empty', 'body.profile-page .gear-search',
    'body.profile-page .gear-row', 'body.profile-page .profile-cover-badge', 'body.profile-page .profile-shop-card',
    'body.profile-page .shop-buy-card', 'body.profile-page .gear-note',
    'body.shop-page', 'body.shop-page .sh-mainpanel', 'body.shop-page .sh-pagebody',
    'body.shop-page .shop-page-shell', 'body.shop-page .shop-market-card', 'body.shop-page .shop-market-cover',
    'body.shop-page .cart-row', 'body.shop-page .cart-thumb',
    'body.shop-page .shop-market-body', 'body.shop-page .shop-buy-card', 'body.shop-page .feed-left-rail',
    'body.shop-page .feed-left-nav', 'body.shop-page .shop-nav-filters', 'body.shop-page .shop-brand-nav',
    'body.shop-page .feed-left-rail-page-head', 'body.shop-page .shop-brand-banner', 'body.shop-page .ig-feed-header',
    '.ig-card', '.ig-insta-card', '.ig-post-card', '.post', '.tl-card', '.pv-right',
    '.row', '.row-sm', '.col-lg-4', '.col-lg-8', '.col-md-4', '.col-md-8', '.hd', '.bd', '.mt-2',
    '.c-footer', '.app-footer', '.feed-ig-btn', '.feed-ig-link', '.nav-link',
    'input:not([type=checkbox]):not([type=radio])', 'textarea', 'select', '.form-control',
    '.search-input', '.table', '.table td', '.table th', '.nav-tabs', '.bestprofile-nav',
    '.live-main', '.live-wrap', '.row-card', '.row-main', '.live-rail', '.live-rail-head',
    '.rm-modal', '.rm-content', '.rm-body', '.rm-footer', '.c-modal', '.c-bodywrap',
    '.reply-box', '.comment-item', '.media-tile', '.sidebar-pill', '.signbox',
    '.page-shell', '.page-shell .card', '.bd-primary', '.sh-card',
    '.dropdown-item', '.dropdown-menu-link', '.bestchat-menu-item', '.dropdown-bestnoti-item',
    '.yt-topbar-left', '.yt-topbar-center', '.yt-topbar-right', '.yt-chat-switch',
    '.public-publisher-search', '.feed-top-search', '.feed-desktop-layout', '.feed-desktop-center', '.feed-left-nav', '.ttNavLeftbar', '.sh-pagetitle-left',
    '.mf-feed', '.mf-card', '.mf-head:not(.mf-head--on-media)', '.mf-foot', '.mf-actions', '.mf-menu',
    '.tt-comments-wrap', '.tt-readmore-wrap', '.tt-menu-wrap', '.tt-live-wrap', '.tt-live-door-frame',
    '.tt-comments-head', '.tt-comments-list', '.tt-comments-foot',
    '.tt-rm-head', '.tt-rm-body', '.tt-menu-head', '.tt-menu-body',
    '#ttLeftbarOverlays .tt-profile-wrap', '#ttLeftbarOverlays .tt-profile-head', '#ttLeftbarOverlays .tt-profile-body',
    '.msb-profile-door-host .tt-profile-wrap', '.msb-profile-door-host .tt-profile-head', '.msb-profile-door-host .tt-profile-body',
    '.ig-post-shell', '.ig-insta-header', '.ig-insta-caption-block', '.ig-insta-actionbar', '.ig-insta-dots-host',
    'body.dashboard-page', 'body.dashboard-page .sh-mainpanel', 'body.dashboard-page .sh-pagebody',
    'body.dashboard-page .sh-pagetitle', 'body.dashboard-page .card', 'body.dashboard-page .card-body',
    'body.dashboard-page .card-header', 'body.dashboard-page .card-footer', 'body.dashboard-page .alert',
    'body.dashboard-modal-page', 'body.dashboard-page.dashboard-modal-page .sh-mainpanel',
    'body.dashboard-page.dashboard-modal-page .sh-pagebody', 'body.dashboard-page.dashboard-modal-page .card',
    'body.dashboard-page.dashboard-modal-page .card-body', 'body.dashboard-page.dashboard-modal-page .alert',
    'body.org-app', 'body.org-app .sh-mainpanel', 'body.org-app .sh-pagebody',
    'body.org-app .sh-sideleft-menu', 'body.org-app .org-sideleft-scroll',
    'body.org-app .chat-shell', 'body.org-app .chat-left', 'body.org-app .chat-right',
    'body.org-app .card.fixed-card'
  ].join(',\nhtml[data-msb-appearance] ');

  function movePaletteStyleToDocumentEnd(style){
    if (!style) return;
    var root = document.body || document.documentElement;
    if (style.parentNode) {
      style.parentNode.removeChild(style);
    }
    var tail = document.getElementById('msb-palette-post-card-tail');
    if (tail && tail.parentNode === root) {
      root.insertBefore(style, tail);
    } else {
      root.appendChild(style);
    }
  }

  var PALETTE_FEED_SURFACE_SELECTORS = [
    'body.dark-auto .card', 'body.dark-auto .card-header', 'body.dark-auto .card-body', 'body.dark-auto .card-footer',
    'html.dark-auto .card-header',
    'body.feed-insta-ui .desktop-only .ig-insta-card.ig-post-card',
    'body.feed-insta-ui .ig-insta-card', 'body.feed-insta-ui .ig-insta-card .card-header',
    'body.feed-insta-ui .ig-insta-card .ig-insta-header',
    'body.feed-insta-ui .ig-insta-card .ig-insta-caption-block', 'body.feed-insta-ui .ig-insta-card .ig-insta-actionbar',
    'body.feed-insta-ui .ig-insta-card .ig-insta-actionbar.ig-underbar', 'body.feed-insta-ui .ig-insta-card .card-body',
    'body.feed-insta-ui .ig-insta-card .ig-viewer-body', 'body.feed-insta-ui .ig-insta-card .ig-details',
    'body.feed-insta-ui .ig-insta-card .ig-insta-tools-flyout',
    'body .mf-feed .mf-card', 'body .mf-feed .mf-card .mf-head:not(.mf-head--on-media)', 'body .mf-feed .mf-card .mf-foot',
    'body .mf-feed .mf-card .mf-actions', 'body .mf-feed .mf-card .mf-title', 'body .mf-feed .mf-card .mf-body',
    '.mf-card .mf-head:not(.mf-head--on-media)', '.mf-card .mf-foot', '.mf-card .mf-actions', '.mf-card .mf-title', '.mf-card .mf-body',
    '.post.public-post-card', '.post.public-post-card .post-header', '.post.public-post-card .actions',
    '.post.public-post-card .post-copy', '.standard-text-card', '.standard-text-topbar',
    '.standard-media-topbar', '.standard-media-bottom',
    '.ig-post-card .card-header'
  ].join(',\nhtml[data-msb-appearance] ');

  function ensurePalettePaintStyle(){
    var style = document.getElementById(PALETTE_PAINT_STYLE_ID);
    if (!style) {
      style = document.createElement('style');
      style.id = PALETTE_PAINT_STYLE_ID;
      document.head.appendChild(style);
    }
    style.textContent =
      'html[data-msb-appearance],\n' +
      'html[data-msb-appearance] ' + PALETTE_PAINT_SELECTORS + ' {\n' +
      '  background-color: var(--msb-palette-bg) !important;\n' +
      '  background-image: none !important;\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '  border-color: var(--msb-palette-border) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .btn-primary,\n' +
      'html[data-msb-appearance] a.btn-primary,\n' +
      'html[data-msb-appearance] button.btn-primary,\n' +
      'html[data-msb-appearance] .btn-success,\n' +
      'html[data-msb-appearance] button.btn-success,\n' +
      'html[data-msb-appearance] a.btn-success,\n' +
      'html[data-msb-appearance] .btn-info,\n' +
      'html[data-msb-appearance] .ch-btn-primary,\n' +
      'html[data-msb-appearance] a.ch-btn-primary,\n' +
      'html[data-msb-appearance] button.ch-btn-primary,\n' +
      'html[data-msb-appearance] body.profile-page .gear-detail-open-btn,\n' +
      'html[data-msb-appearance] body.profile-page .gear-upload-btn,\n' +
      'html[data-msb-appearance] body.profile-page .about-edit-btn,\n' +
      'html[data-msb-appearance] body.profile-page .profile-shop-buy-btn,\n' +
      'html[data-msb-appearance] body.org-app .btn-primary,\n' +
      'html[data-msb-appearance] body.org-app a.btn-primary,\n' +
      'html[data-msb-appearance] body.org-app button.btn-primary,\n' +
      'html[data-msb-appearance] body.org-app .ch-btn-primary,\n' +
      'html[data-msb-appearance] .bg-primary,\n' +
      'html[data-msb-appearance] .bubble.me {\n' +
      '  background-color: var(--msb-palette-btn-bg) !important;\n' +
      '  background-image: none !important;\n' +
      '  color: var(--msb-palette-btn-text) !important;\n' +
      '  border-color: var(--msb-palette-btn-bg) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .btn-primary:hover,\n' +
      'html[data-msb-appearance] a.btn-primary:hover,\n' +
      'html[data-msb-appearance] button.btn-primary:hover,\n' +
      'html[data-msb-appearance] .btn-success:hover,\n' +
      'html[data-msb-appearance] .ch-btn-primary:hover,\n' +
      'html[data-msb-appearance] body.profile-page .gear-detail-open-btn:hover,\n' +
      'html[data-msb-appearance] body.profile-page .gear-upload-btn:hover,\n' +
      'html[data-msb-appearance] body.org-app .btn-primary:hover,\n' +
      'html[data-msb-appearance] body.org-app .ch-btn-primary:hover {\n' +
      '  background-color: var(--msb-palette-btn-hover-bg) !important;\n' +
      '  border-color: var(--msb-palette-btn-hover-bg) !important;\n' +
      '  color: var(--msb-palette-btn-text) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .btn:not(.btn-primary):not(.btn-success):not(.btn-info),\n' +
      'html[data-msb-appearance] button.btn:not(.btn-primary):not(.btn-success),\n' +
      'html[data-msb-appearance] a.btn:not(.btn-primary):not(.btn-success),\n' +
      'html[data-msb-appearance] .btn-light,\n' +
      'html[data-msb-appearance] .btn-white,\n' +
      'html[data-msb-appearance] .btn-soft,\n' +
      'html[data-msb-appearance] .btn-outline-primary,\n' +
      'html[data-msb-appearance] .btn-outline-secondary,\n' +
      'html[data-msb-appearance] .btn-outline-success,\n' +
      'html[data-msb-appearance] .ch-btn-ghost,\n' +
      'html[data-msb-appearance] a.ch-btn-ghost,\n' +
      'html[data-msb-appearance] button.ch-btn-ghost,\n' +
      'html[data-msb-appearance] .ig-btn,\n' +
      'html[data-msb-appearance] .feed-ig-btn,\n' +
      'html[data-msb-appearance] .action-btn,\n' +
      'html[data-msb-appearance] .topicon-btn,\n' +
      'html[data-msb-appearance] .yt-icon-btn,\n' +
      'html[data-msb-appearance] .search-btn,\n' +
      'html[data-msb-appearance] .iconbtn,\n' +
      'html[data-msb-appearance] .messages-shell-action-btn,\n' +
      'html[data-msb-appearance] body.profile-page .gear-nav-item,\n' +
      'html[data-msb-appearance] body.org-app .btn:not(.btn-primary):not(.btn-success),\n' +
      'html[data-msb-appearance] body.org-app button.btn,\n' +
      'html[data-msb-appearance] body.org-app .btn-outline-primary,\n' +
      'html[data-msb-appearance] body.org-app .btn-outline-secondary,\n' +
      'html[data-msb-appearance] body.org-app .ch-btn-ghost,\n' +
      'html[data-msb-appearance] body.org-app .pin-btn,\n' +
      'html[data-msb-appearance] body.org-app .btn-close-x {\n' +
      '  background-color: var(--msb-palette-surface-2, var(--msb-palette-bg)) !important;\n' +
      '  background-image: none !important;\n' +
      '  border-color: var(--msb-palette-border-strong, var(--msb-palette-border)) !important;\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .btn:not(.btn-primary):not(.btn-success):hover,\n' +
      'html[data-msb-appearance] button.btn:hover,\n' +
      'html[data-msb-appearance] .btn-outline-primary:hover,\n' +
      'html[data-msb-appearance] .ch-btn-ghost:hover,\n' +
      'html[data-msb-appearance] .ig-btn:hover,\n' +
      'html[data-msb-appearance] .feed-ig-btn:hover,\n' +
      'html[data-msb-appearance] body.profile-page .gear-nav-item:hover,\n' +
      'html[data-msb-appearance] body.org-app .btn:hover,\n' +
      'html[data-msb-appearance] body.org-app .ch-btn-ghost:hover {\n' +
      '  background-color: var(--msb-palette-hover-bg) !important;\n' +
      '  border-color: var(--msb-palette-border-strong, var(--msb-palette-border)) !important;\n' +
      '  color: var(--msb-palette-text-on-hover, var(--msb-palette-text)) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .btn-primary i,\n' +
      'html[data-msb-appearance] .btn-primary .icon,\n' +
      'html[data-msb-appearance] button.btn-primary i,\n' +
      'html[data-msb-appearance] a.btn-primary i,\n' +
      'html[data-msb-appearance] .ch-btn-primary i,\n' +
      'html[data-msb-appearance] body.profile-page .gear-detail-open-btn i,\n' +
      'html[data-msb-appearance] body.org-app .btn-primary i {\n' +
      '  color: var(--msb-palette-btn-text) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .btn i,\n' +
      'html[data-msb-appearance] .btn .icon,\n' +
      'html[data-msb-appearance] button.btn i,\n' +
      'html[data-msb-appearance] .ig-btn i,\n' +
      'html[data-msb-appearance] .feed-ig-btn i,\n' +
      'html[data-msb-appearance] body.org-app .btn i {\n' +
      '  color: inherit !important;\n' +
      '}\n' +
      'html[data-msb-appearance] ' + PALETTE_SPAN_ACCENT_SURFACE_SELECTORS + ' {\n' +
      '  background-color: var(--msb-palette-action-soft, var(--msb-palette-nav-active-bg)) !important;\n' +
      '  background-image: none !important;\n' +
      '  border-color: var(--msb-palette-border-strong, var(--msb-palette-border)) !important;\n' +
      '  color: var(--msb-palette-nav-active-text, var(--msb-palette-action, var(--msb-palette-text))) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] ' + PALETTE_SPAN_SURFACE_SELECTORS + ' {\n' +
      '  background-color: var(--msb-palette-surface-2, var(--msb-palette-hover-bg, var(--msb-palette-bg))) !important;\n' +
      '  background-image: none !important;\n' +
      '  border-color: var(--msb-palette-border-strong, var(--msb-palette-border)) !important;\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .stat-pill .num,\n' +
      'html[data-msb-appearance] .pv-likepill span {\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '  background-color: transparent !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .stat-pill .lbl,\n' +
      'html[data-msb-appearance] .stat-pill .sub {\n' +
      '  color: var(--msb-palette-text-muted, var(--msb-palette-text)) !important;\n' +
      '  background-color: transparent !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .stat-pill .icon,\n' +
      'html[data-msb-appearance] .stat-pill i,\n' +
      'html[data-msb-appearance] .commerce-pill i,\n' +
      'html[data-msb-appearance] .pv-likepill i,\n' +
      'html[data-msb-appearance] .feed-badge i,\n' +
      'html[data-msb-appearance] .badge i,\n' +
      'html[data-msb-appearance] span.sidebar-chip i {\n' +
      '  color: var(--msb-palette-icon, var(--msb-palette-text)) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .stat-pill .icon {\n' +
      '  background-color: var(--msb-palette-hover-bg, var(--msb-palette-surface-2)) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.profile-page .gear-nav-section-label,\n' +
      'html[data-msb-appearance] body.profile-page .gear-nav-item-meta,\n' +
      'html[data-msb-appearance] body.profile-page .gear-upload-hint,\n' +
      'html[data-msb-appearance] body.profile-page .gear-detail-control-label,\n' +
      'html[data-msb-appearance] body.profile-page .gear-nav-section-chevron,\n' +
      'html[data-msb-appearance] body.profile-page .gear-save-state,\n' +
      'html[data-msb-appearance] .nav-link > span,\n' +
      'html[data-msb-appearance] .sh-icon-link > span,\n' +
      'html[data-msb-appearance] body.org-app .sidebar-meta > span,\n' +
      'html[data-msb-appearance] .mf-num,\n' +
      'html[data-msb-appearance] .pv-n,\n' +
      'html[data-msb-appearance] .pv-views,\n' +
      'html[data-msb-appearance] .ig-story-name,\n' +
      'html[data-msb-appearance] span.muted {\n' +
      '  background-color: transparent !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.profile-page .gear-nav-item-meta,\n' +
      'html[data-msb-appearance] body.profile-page .gear-upload-hint,\n' +
      'html[data-msb-appearance] body.profile-page .gear-nav-section-chevron,\n' +
      'html[data-msb-appearance] body.profile-page .gear-save-state:not(.is-saving):not(.is-saved):not(.is-error),\n' +
      'html[data-msb-appearance] span.muted {\n' +
      '  color: var(--msb-palette-text-muted, var(--msb-palette-text)) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.profile-page .ig-username,\n' +
      'html[data-msb-appearance] body.profile-page .ig-stat,\n' +
      'html[data-msb-appearance] body.profile-page b.ig-stat,\n' +
      'html[data-msb-appearance] body.profile-page .ig-bio {\n' +
      '  background-color: transparent !important;\n' +
      '  background-image: none !important;\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.profile-page .ig-tab:not(.active) {\n' +
      '  background-color: transparent !important;\n' +
      '  color: var(--msb-palette-text-muted, var(--msb-palette-text)) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.profile-page .ig-tab.active {\n' +
      '  background-color: var(--msb-palette-nav-active-bg, var(--msb-palette-action-soft)) !important;\n' +
      '  color: var(--msb-palette-nav-active-text, var(--msb-palette-action, var(--msb-palette-text))) !important;\n' +
      '  border-top-color: var(--msb-palette-nav-active-text, var(--msb-palette-action, var(--msb-palette-text))) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.profile-page .ig-tab.active i {\n' +
      '  color: inherit !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.profile-page .gear-nav-section-toggle {\n' +
      '  background-color: var(--msb-palette-action-soft, var(--msb-palette-hover-bg)) !important;\n' +
      '  background-image: none !important;\n' +
      '  border: 1px solid var(--msb-palette-border) !important;\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.profile-page .gear-nav-section-toggle:hover,\n' +
      'html[data-msb-appearance] body.profile-page .gear-nav-section-toggle:focus {\n' +
      '  background-color: var(--msb-palette-nav-hover, var(--msb-palette-hover-bg)) !important;\n' +
      '  color: var(--msb-palette-text-on-hover, var(--msb-palette-text)) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.profile-page .gear-nav-item {\n' +
      '  background-color: transparent !important;\n' +
      '  background-image: none !important;\n' +
      '  border-color: transparent !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.profile-page .gear-nav-section-icon {\n' +
      '  background-color: var(--msb-palette-nav-active-bg, var(--msb-palette-action-soft)) !important;\n' +
      '  color: var(--msb-palette-action, var(--msb-palette-icon)) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.profile-page .gear-nav-section-icon i,\n' +
      'html[data-msb-appearance] body.profile-page .gear-nav-section-chevron {\n' +
      '  color: var(--msb-palette-action, var(--msb-palette-icon)) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.profile-page .ig-gallery-search button {\n' +
      '  background-color: var(--msb-palette-btn-bg, var(--msb-palette-action)) !important;\n' +
      '  border-color: var(--msb-palette-btn-bg, var(--msb-palette-action)) !important;\n' +
      '  color: var(--msb-palette-btn-text, #ffffff) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-wrap,\n' +
      'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-head,\n' +
      'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-body,\n' +
      'html[data-msb-appearance] .msb-profile-door-host .tt-profile-wrap,\n' +
      'html[data-msb-appearance] .msb-profile-door-host .tt-profile-head,\n' +
      'html[data-msb-appearance] .msb-profile-door-host .tt-profile-body {\n' +
      '  background-color: var(--msb-palette-bg) !important;\n' +
      '  background-image: none !important;\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '  border-color: var(--msb-palette-border) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-head .title,\n' +
      'html[data-msb-appearance] .msb-profile-door-host .tt-profile-head .title {\n' +
      '  background: transparent !important;\n' +
      '  background-color: transparent !important;\n' +
      '  background-image: none !important;\n' +
      '  border-color: transparent !important;\n' +
      '  box-shadow: none !important;\n' +
      '}\n' +
      'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-head .title,\n' +
      'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-name,\n' +
      'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-badge,\n' +
      'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-nav a,\n' +
      'html[data-msb-appearance] .msb-profile-door-host .tt-profile-head .title,\n' +
      'html[data-msb-appearance] .msb-profile-door-host .tt-profile-name,\n' +
      'html[data-msb-appearance] .msb-profile-door-host .tt-profile-badge,\n' +
      'html[data-msb-appearance] .msb-profile-door-host .tt-profile-nav a {\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-email,\n' +
      'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-code,\n' +
      'html[data-msb-appearance] .msb-profile-door-host .tt-profile-email,\n' +
      'html[data-msb-appearance] .msb-profile-door-host .tt-profile-code {\n' +
      '  color: var(--msb-palette-text-muted, var(--msb-palette-text)) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-nav a .icon,\n' +
      'html[data-msb-appearance] .msb-profile-door-host .tt-profile-nav a .icon {\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '  opacity: 1 !important;\n' +
      '}\n' +
      'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-wrap .tt-close,\n' +
      'html[data-msb-appearance] .msb-profile-door-host .tt-profile-wrap .tt-close {\n' +
      '  background-color: var(--msb-palette-surface-2, var(--msb-palette-hover-bg)) !important;\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '  border-color: var(--msb-palette-border-strong, var(--msb-palette-border)) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-wrap .tt-close:hover,\n' +
      'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-wrap .tt-close:focus,\n' +
      'html[data-msb-appearance] .msb-profile-door-host .tt-profile-wrap .tt-close:hover,\n' +
      'html[data-msb-appearance] .msb-profile-door-host .tt-profile-wrap .tt-close:focus {\n' +
      '  background-color: var(--msb-palette-hover-bg) !important;\n' +
      '  color: var(--msb-palette-text-on-hover, var(--msb-palette-text)) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-wrap .tt-close i,\n' +
      'html[data-msb-appearance] .msb-profile-door-host .tt-profile-wrap .tt-close i {\n' +
      '  color: inherit !important;\n' +
      '}\n' +
      'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-nav a:hover,\n' +
      'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-nav a:focus,\n' +
      'html[data-msb-appearance] .msb-profile-door-host .tt-profile-nav a:hover,\n' +
      'html[data-msb-appearance] .msb-profile-door-host .tt-profile-nav a:focus {\n' +
      '  background-color: var(--msb-palette-hover-bg) !important;\n' +
      '  color: var(--msb-palette-text-on-hover, var(--msb-palette-text)) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-nav a:hover .icon,\n' +
      'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-nav a:focus .icon,\n' +
      'html[data-msb-appearance] .msb-profile-door-host .tt-profile-nav a:hover .icon,\n' +
      'html[data-msb-appearance] .msb-profile-door-host .tt-profile-nav a:focus .icon {\n' +
      '  color: inherit !important;\n' +
      '}\n' +
      'html[data-msb-appearance] #ttLeftbarOverlays .tt-profile-divider,\n' +
      'html[data-msb-appearance] .msb-profile-door-host .tt-profile-divider {\n' +
      '  background-color: var(--msb-palette-border-strong, var(--msb-palette-border)) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .msg-row:not(.me) .bubble:not(.has-media),\n' +
      'html[data-msb-appearance] .conv-history .bubble:not(.me):not(.has-media),\n' +
      'html[data-msb-appearance] #chatBox .bubble:not(.me):not(.has-media) {\n' +
      '  background-color: var(--msb-palette-bubble-bg) !important;\n' +
      '  background-image: none !important;\n' +
      '  color: var(--msb-palette-bubble-text) !important;\n' +
      '  border-color: var(--msb-palette-bubble-border) !important;\n' +
      '  box-shadow: none !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .bubble:not(.me):not(.has-media) .msgText,\n' +
      'html[data-msb-appearance] .bubble:not(.me):not(.has-media) .msg-edited {\n' +
      '  color: var(--msb-palette-bubble-text) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .bubble:not(.me):not(.has-media) .msg-meta {\n' +
      '  color: var(--msb-palette-bubble-meta) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .bubble:not(.me) .msg-reply-line {\n' +
      '  background-color: var(--msb-palette-bubble-reply-bg) !important;\n' +
      '  border-left-color: var(--msb-palette-border-strong) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .bubble:not(.me) .msg-reply-line-title {\n' +
      '  color: var(--msb-palette-bubble-text) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .bubble:not(.me) .msg-reply-line-text {\n' +
      '  color: var(--msb-palette-bubble-meta) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .feed-ig-logo {\n' +
      '  background: linear-gradient(135deg, var(--msb-palette-action), var(--msb-palette-accent)) !important;\n' +
      '  background-color: transparent !important;\n' +
      '  color: #ffffff !important;\n' +
      '  box-shadow: 0 12px 30px rgba(15, 23, 42, 0.18) !important;\n' +
      '  border: 0 !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .ig-logo {\n' +
      '  background: linear-gradient(135deg, var(--msb-palette-action), var(--msb-palette-accent)) !important;\n' +
      '  background-color: transparent !important;\n' +
      '  color: #ffffff !important;\n' +
      '  border: 0 !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .feed-ig-rail {\n' +
      '  background-color: var(--msb-palette-bg) !important;\n' +
      '  color: var(--msb-palette-text-on-nav, var(--msb-palette-text)) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] img,\n' +
      'html[data-msb-appearance] video,\n' +
      'html[data-msb-appearance] canvas,\n' +
      'html[data-msb-appearance] iframe,\n' +
      'html[data-msb-appearance] svg,\n' +
      'html[data-msb-appearance] .bestprofile-avatar img,\n' +
      'html[data-msb-appearance] [data-live-avatar] img {\n' +
      '  background-color: transparent !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .create-post-modal,\n' +
      'html[data-msb-appearance] .global-live-modal,\n' +
      'html[data-msb-appearance] .modal-backdrop,\n' +
      'html[data-msb-appearance] .studio-live-modal {\n' +
      '  background-color: rgba(0,0,0,0.55) !important;\n' +
      '  background-image: none !important;\n' +
      '}\n' +
      'html[data-msb-appearance] ' + PALETTE_FG_SELECTORS + ' {\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .icon,\n' +
      'html[data-msb-appearance] i.icon,\n' +
      'html[data-msb-appearance] [class*="ion-"],\n' +
      'html[data-msb-appearance] .fa {\n' +
      '  color: var(--msb-palette-icon) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] a.gear-detail-open-btn,\n' +
      'html[data-msb-appearance] body.profile-page a.gear-detail-open-btn {\n' +
      '  background-color: var(--msb-palette-btn-bg) !important;\n' +
      '  background-image: none !important;\n' +
      '  border-color: var(--msb-palette-btn-bg) !important;\n' +
      '  color: var(--msb-palette-btn-text) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] a.gear-detail-open-btn:hover,\n' +
      'html[data-msb-appearance] a.gear-detail-open-btn:focus {\n' +
      '  background-color: var(--msb-palette-btn-hover-bg) !important;\n' +
      '  border-color: var(--msb-palette-btn-hover-bg) !important;\n' +
      '  color: var(--msb-palette-btn-text) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] a.gear-detail-open-btn i,\n' +
      'html[data-msb-appearance] a.gear-detail-open-btn .icon,\n' +
      'html[data-msb-appearance] a.gear-detail-open-btn [class*="ion-"] {\n' +
      '  color: var(--msb-palette-btn-text) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] a:not(.btn-primary):not(.bg-primary):not(.messages-shell-tab.active):not(.feed-ig-logo):not(.gear-detail-open-btn):not(.gear-upload-btn):not(.about-edit-btn):not(.profile-shop-buy-btn):not(.ch-btn-primary):not(.btn-success):not(.btn):not(.ch-btn-ghost):not(.feed-ig-btn):not(.feed-tab-link),\n' +
      'html[data-msb-appearance] a.feed-ig-logo-label,\n' +
      'html[data-msb-appearance] .feed-ig-logo-label {\n' +
      '  color: var(--msb-palette-text-on-nav, var(--msb-palette-text)) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .text-muted,\n' +
      'html[data-msb-appearance] small,\n' +
      'html[data-msb-appearance] .small {\n' +
      '  color: var(--msb-palette-text-muted) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] input::placeholder,\n' +
      'html[data-msb-appearance] textarea::placeholder {\n' +
      '  color: var(--msb-palette-placeholder) !important;\n' +
      '  opacity: 1 !important;\n' +
      '}\n' +
      'html[data-msb-appearance] ' + PALETTE_FG_HOVER_SELECTORS + ' {\n' +
      '  color: var(--msb-palette-text-on-hover) !important;\n' +
      '  background-color: var(--msb-palette-hover-bg) !important;\n' +
      '  border-color: var(--msb-palette-border-strong) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] ' + paletteHoverChildSelectors('.icon') + ',\n' +
      'html[data-msb-appearance] ' + paletteHoverChildSelectors('i.icon') + ',\n' +
      'html[data-msb-appearance] ' + paletteHoverChildSelectors('[class*="ion-"]') + ',\n' +
      'html[data-msb-appearance] ' + paletteHoverChildSelectors('.fa') + ',\n' +
      'html[data-msb-appearance] ' + paletteHoverChildSelectors('svg') + ' {\n' +
      '  color: var(--msb-palette-icon-on-hover) !important;\n' +
      '  stroke: currentColor !important;\n' +
      '}\n' +
      'html[data-msb-appearance] ' + PALETTE_FG_HOVER_SELECTORS + ' .text-muted,\n' +
      'html[data-msb-appearance] ' + PALETTE_FG_HOVER_SELECTORS + ' small,\n' +
      'html[data-msb-appearance] ' + PALETTE_FG_HOVER_SELECTORS + ' .small {\n' +
      '  color: var(--msb-palette-text-on-hover) !important;\n' +
      '  opacity: 0.88 !important;\n' +
      '}\n' +
      'html[data-msb-appearance] ' + PALETTE_FG_HOVER_EXCLUDE_SELECTORS + ' {\n' +
      '  background-color: transparent !important;\n' +
      '  background-image: none !important;\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] ' + PALETTE_NAV_HOVER_SELECTORS + ' {\n' +
      '  color: var(--msb-palette-text-on-nav-hover) !important;\n' +
      '  background-color: var(--msb-palette-nav-hover) !important;\n' +
      '  border-color: var(--msb-palette-border-strong) !important;\n' +
      '}\n' +
      paletteNavChildSelectorBlock(PALETTE_NAV_HOVER_SELECTORS, 'color', 'var(--msb-palette-icon-on-nav-hover)') +
      'html[data-msb-appearance] ' + PALETTE_NAV_HOVER_SELECTORS + ' .feed-left-nav-ic svg,\n' +
      'html[data-msb-appearance] ' + PALETTE_NAV_HOVER_SELECTORS + ' .feed-right-nav-ic svg {\n' +
      '  color: var(--msb-palette-icon-on-nav-hover) !important;\n' +
      '  stroke: currentColor !important;\n' +
      '}\n' +
      'html[data-msb-appearance] ' + PALETTE_NAV_ACTIVE_SELECTORS + ':not(:hover) {\n' +
      '  color: var(--msb-palette-nav-active-text) !important;\n' +
      '  background-color: var(--msb-palette-nav-active-bg) !important;\n' +
      '}\n' +
      paletteNavChildSelectorBlock(PALETTE_NAV_ACTIVE_SELECTORS + ':not(:hover)', 'color', 'var(--msb-palette-nav-active-icon)') +
      'html[data-msb-appearance] ' + PALETTE_NAV_REST_SELECTORS + ':not(.active):not(.is-active) {\n' +
      '  color: var(--msb-palette-text-on-nav) !important;\n' +
      '  background-color: transparent !important;\n' +
      '}\n' +
      paletteNavChildSelectorBlock(PALETTE_NAV_REST_SELECTORS + ':not(.active):not(.is-active)', 'color', 'var(--msb-palette-icon)') +
      'html[data-msb-appearance] ' + PALETTE_NAV_REST_SELECTORS + ':not(.active):not(.is-active) .sidebar-meta,\n' +
      'html[data-msb-appearance] ' + PALETTE_NAV_REST_SELECTORS + ':not(.active):not(.is-active) .sidebar-chip {\n' +
      '  color: var(--msb-palette-text-muted) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] ' + PALETTE_NAV_REST_SELECTORS + ':not(.active):not(.is-active) .feed-left-nav-ic svg,\n' +
      'html[data-msb-appearance] ' + PALETTE_NAV_REST_SELECTORS + ':not(.active):not(.is-active) .feed-right-nav-ic svg {\n' +
      '  color: var(--msb-palette-icon) !important;\n' +
      '  stroke: currentColor !important;\n' +
      '}\n' +
      'html[data-msb-appearance] ' + PALETTE_ORG_FOCUS_RESET_SELECTORS + ' {\n' +
      '  color: var(--msb-palette-text-on-nav) !important;\n' +
      '  background-color: transparent !important;\n' +
      '}\n' +
      paletteNavChildSelectorBlock(PALETTE_ORG_FOCUS_RESET_SELECTORS, 'color', 'var(--msb-palette-icon)') +
      'html[data-msb-appearance] ' + PALETTE_ORG_FOCUS_RESET_SELECTORS + ' .sidebar-meta,\n' +
      'html[data-msb-appearance] ' + PALETTE_ORG_FOCUS_RESET_SELECTORS + ' .sidebar-chip {\n' +
      '  color: var(--msb-palette-text-muted) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-stories-menu-btn,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-stories-brand,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-top-act {\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '  background: transparent !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-stories-menu-btn .fa,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-stories-menu-btn .icon,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-top-mic .fa,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-top-live .fa,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-top-live i,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-story-create .ig-story-ring-create .icon,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-story-create .ig-story-ring-create [class*="ion-"],\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-story-empty-icon,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-story-empty-icon .icon,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-story-empty-icon [class*="ion-"] {\n' +
      '  color: var(--msb-palette-icon) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-top-live span {\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-story-name,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-story-empty .ig-story-name {\n' +
      '  color: var(--msb-palette-text-muted) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-top-mic,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-top-live,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-story-create .ig-story-ring-create,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-story-empty-icon {\n' +
      '  border-color: var(--msb-palette-border-strong) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-story-create .ig-story-ring-create,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-story-ring-empty,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-story-empty-icon {\n' +
      '  background: transparent !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-stories-menu-btn:hover,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-top-mic:hover,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-top-live:hover,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-story-create:hover .ig-story-ring-create {\n' +
      '  background-color: var(--msb-palette-nav-hover) !important;\n' +
      '  border-color: var(--msb-palette-nav-hover) !important;\n' +
      '  color: var(--msb-palette-text-on-nav-hover) !important;\n' +
      '  opacity: 1 !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-stories-menu-btn:hover .fa,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-stories-menu-btn:hover .icon,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-top-mic:hover .fa,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-top-live:hover .fa,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-top-live:hover i,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-top-live:hover span,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-story-create:hover .ig-story-ring-create .icon,\n' +
      'html[data-msb-appearance] .ig-feed-header .ig-story-create:hover .ig-story-ring-create [class*="ion-"] {\n' +
      '  color: var(--msb-palette-icon-on-nav-hover) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .feed-ig-logo:hover,\n' +
      'html[data-msb-appearance] .feed-ig-logo:focus,\n' +
      'html[data-msb-appearance] .ig-logo:hover,\n' +
      'html[data-msb-appearance] .ig-logo:focus {\n' +
      '  background: linear-gradient(135deg, var(--msb-palette-action-strong), var(--msb-palette-action)) !important;\n' +
      '  color: #ffffff !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .btn-primary,\n' +
      'html[data-msb-appearance] .bg-primary {\n' +
      '  color: var(--msb-palette-btn-text) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .messages-shell-tab.active {\n' +
      '  background-color: var(--msb-palette-action-soft) !important;\n' +
      '  color: var(--msb-palette-action) !important;\n' +
      '  border-color: var(--msb-palette-border-strong) !important;\n' +
      '  box-shadow: none !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .btn-primary:hover,\n' +
      'html[data-msb-appearance] .bg-primary:hover {\n' +
      '  background-color: var(--msb-palette-btn-hover-bg) !important;\n' +
      '  color: var(--msb-palette-btn-text) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] .messages-shell-tab.active:hover {\n' +
      '  background-color: var(--msb-palette-nav-hover) !important;\n' +
      '  color: var(--msb-palette-action-strong) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] ' + PALETTE_FEED_SURFACE_SELECTORS + ' {\n' +
      '  background-image: none !important;\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '  border-color: var(--msb-palette-border) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body .mf-feed .mf-card .mf-head.mf-head--on-media,\n' +
      'html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media,\n' +
      'html[data-msb-appearance] body .mf-feed .mf-head.mf-head--on-media,\n' +
      'html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media,\n' +
      'html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media .mf-peer-link,\n' +
      'html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media .mf-meta,\n' +
      'html[data-msb-appearance] body .mf-feed .mf-card:has(.mf-head--on-media) .mf-media-shell,\n' +
      'html[data-msb-appearance] body .mf-feed .mf-card:has(.mf-head--on-media) .mf-media-shell .media-stage,\n' +
      'html[data-msb-appearance] body .mf-feed .mf-card:has(.mf-head--on-media) .mf-media-shell .mf-media {\n' +
      '  background: transparent !important;\n' +
      '  background-color: transparent !important;\n' +
      '  background-image: none !important;\n' +
      '  border-color: transparent !important;\n' +
      '  box-shadow: none !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media .mf-name,\n' +
      'html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media .mf-time,\n' +
      'html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media .mf-dot {\n' +
      '  color: #fff !important;\n' +
      '  text-shadow: 0 2px 10px rgba(0, 0, 0, .34) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-insta-username,\n' +
      'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-insta-username a,\n' +
      'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-insta-more,\n' +
      'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-insta-actions-left .ig-count,\n' +
      'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-insta-actions-right .ig-count,\n' +
      'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-insta-likes-row,\n' +
      'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-insta-caption-block,\n' +
      'html[data-msb-appearance] .mf-card .mf-name,\n' +
      'html[data-msb-appearance] .mf-card .mf-title,\n' +
      'html[data-msb-appearance] .mf-card .mf-menu-btn {\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-insta-time,\n' +
      'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-insta-dot,\n' +
      'html[data-msb-appearance] .mf-card .mf-time,\n' +
      'html[data-msb-appearance] .mf-card .mf-dot {\n' +
      '  color: var(--msb-palette-text-muted) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-insta-actions-left .ig-circle,\n' +
      'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-insta-actions-right .ig-circle,\n' +
      'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-underbar .ig-circle {\n' +
      '  background: transparent !important;\n' +
      '  border-color: transparent !important;\n' +
      '  box-shadow: none !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-insta-actions-left .ig-circle i,\n' +
      'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-insta-actions-right .ig-circle i,\n' +
      'html[data-msb-appearance] body.feed-insta-ui .ig-insta-card .ig-insta-more,\n' +
      'html[data-msb-appearance] .mf-card .mf-actions .icon,\n' +
      'html[data-msb-appearance] .mf-card .mf-actions .fa,\n' +
      'html[data-msb-appearance] .mf-card .mf-actions [class*="ion-"],\n' +
      'html[data-msb-appearance] .post.public-post-card .more-btn,\n' +
      'html[data-msb-appearance] .post.public-post-card .standard-media-more,\n' +
      'html[data-msb-appearance] .post.public-post-card .action-btn i,\n' +
      'html[data-msb-appearance] .standard-text-btn i {\n' +
      '  color: var(--msb-palette-icon) !important;\n' +
      '  fill: currentColor !important;\n' +
      '}\n' +
      'html[data-msb-appearance] a:not(.btn-primary):not(.bg-primary):not(.messages-shell-tab.active):not(.feed-ig-logo):not(.mf-peer-link):not(.post-author-link):not(.gear-detail-open-btn):not(.gear-upload-btn):not(.about-edit-btn):not(.profile-shop-buy-btn):not(.ch-btn-primary):not(.btn-success):not(.btn):not(.ch-btn-ghost):not(.feed-ig-btn):hover,\n' +
      'html[data-msb-appearance] a.feed-ig-logo-label:hover,\n' +
      'html[data-msb-appearance] a.feed-ig-logo-label:focus {\n' +
      '  color: var(--msb-palette-link-hover) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] ' + PALETTE_POST_CARD_HEADER_HOVER_SELECTORS + ' {\n' +
      '  background-color: transparent !important;\n' +
      '  background-image: none !important;\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '  border-color: transparent !important;\n' +
      '  box-shadow: none !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.org-app .card-body .row,\n' +
      'html[data-msb-appearance] body.org-app form .row {\n' +
      '  background-color: transparent !important;\n' +
      '  background-image: none !important;\n' +
      '  color: inherit !important;\n' +
      '  border-color: transparent !important;\n' +
      '  box-shadow: none !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.org-app .form-group label,\n' +
      'html[data-msb-appearance] body.org-app form label {\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.org-app .form-control,\n' +
      'html[data-msb-appearance] body.org-app select.form-control,\n' +
      'html[data-msb-appearance] body.org-app textarea.form-control {\n' +
      '  background-color: var(--msb-palette-input-bg, var(--msb-palette-surface-2, var(--msb-palette-bg))) !important;\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '  border-color: var(--msb-palette-border-strong, var(--msb-palette-border)) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.org-app .org-surface-page .card-body .row,\n' +
      'html[data-msb-appearance] body.org-app .org-surface-page form .row {\n' +
      '  background-color: transparent !important;\n' +
      '  background-image: none !important;\n' +
      '  color: inherit !important;\n' +
      '  border-color: transparent !important;\n' +
      '  box-shadow: none !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.org-app .org-surface-page .form-group label,\n' +
      'html[data-msb-appearance] body.org-app .org-surface-page form label {\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.org-app .org-surface-page .form-control,\n' +
      'html[data-msb-appearance] body.org-app .org-surface-page select.form-control,\n' +
      'html[data-msb-appearance] body.org-app .org-surface-page textarea.form-control {\n' +
      '  background-color: var(--msb-palette-input-bg, var(--msb-palette-surface-2, var(--msb-palette-bg))) !important;\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '  border-color: var(--msb-palette-border-strong, var(--msb-palette-border)) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.org-app .org-surface-page .btn-primary,\n' +
      'html[data-msb-appearance] body.org-app .org-surface-page a.btn-primary,\n' +
      'html[data-msb-appearance] body.org-app .org-surface-page button.btn-primary,\n' +
      'html[data-msb-appearance] body.org-app .org-surface-page .btn-success,\n' +
      'html[data-msb-appearance] body.org-app .org-surface-page .ch-btn-primary,\n' +
      'html[data-msb-appearance] body.org-app .org-surface-page a.ch-btn-primary,\n' +
      'html[data-msb-appearance] body.org-app .org-surface-page .posts-tabs a.active,\n' +
      'html[data-msb-appearance] body.org-app .org-surface-page .feed-tabs a.active,\n' +
      'html[data-msb-appearance] body.profile-page .gear-upload-btn,\n' +
      'html[data-msb-appearance] body.profile-page .gear-detail-open-btn {\n' +
      '  background-color: var(--msb-palette-btn-bg, var(--msb-palette-action)) !important;\n' +
      '  border-color: var(--msb-palette-btn-bg, var(--msb-palette-action)) !important;\n' +
      '  color: var(--msb-palette-btn-text, #ffffff) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] ' + PALETTE_POST_CARD_HEADER_HOVER_CHILD_SELECTORS + ' {\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body .mf-feed .mf-card .mf-head a:hover .mf-time,\n' +
      'html[data-msb-appearance] body .post.public-post-card .post-header a:hover .time,\n' +
      'html[data-msb-appearance] body .post.public-post-card .standard-media-topbar a:hover .standard-media-time {\n' +
      '  color: var(--msb-palette-text-muted) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.profile-page a.gear-detail-open-btn,\n' +
      'html[data-msb-appearance] body.profile-page .gear-detail-open-btn,\n' +
      'html[data-msb-appearance] body.profile-page label.gear-upload-btn,\n' +
      'html[data-msb-appearance] body.profile-page .gear-upload-btn,\n' +
      'html[data-msb-appearance] body.profile-page .about-edit-btn,\n' +
      'html[data-msb-appearance] body.profile-page .profile-shop-buy-btn {\n' +
      '  background-color: var(--msb-palette-btn-bg, var(--msb-palette-action)) !important;\n' +
      '  background-image: none !important;\n' +
      '  border-color: var(--msb-palette-btn-bg, var(--msb-palette-action)) !important;\n' +
      '  color: var(--msb-palette-btn-text, #ffffff) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.profile-page a.gear-detail-open-btn:hover,\n' +
      'html[data-msb-appearance] body.profile-page a.gear-detail-open-btn:focus,\n' +
      'html[data-msb-appearance] body.profile-page label.gear-upload-btn:hover,\n' +
      'html[data-msb-appearance] body.profile-page .gear-upload-btn:hover {\n' +
      '  background-color: var(--msb-palette-btn-hover-bg, var(--msb-palette-action-strong)) !important;\n' +
      '  border-color: var(--msb-palette-btn-hover-bg, var(--msb-palette-action-strong)) !important;\n' +
      '  color: var(--msb-palette-btn-text, #ffffff) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.profile-page a.gear-detail-open-btn i,\n' +
      'html[data-msb-appearance] body.profile-page a.gear-detail-open-btn .icon,\n' +
      'html[data-msb-appearance] body.profile-page a.gear-detail-open-btn [class*="ion-"],\n' +
      'html[data-msb-appearance] body.profile-page .gear-detail-open-btn i,\n' +
      'html[data-msb-appearance] body.profile-page .gear-detail-open-btn [class*="ion-"],\n' +
      'html[data-msb-appearance] body.profile-page label.gear-upload-btn {\n' +
      '  color: var(--msb-palette-btn-text, #ffffff) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.profile-page .ig-tab.active,\n' +
      'html[data-msb-appearance] body.profile-page .ig-tab.active i {\n' +
      '  background-color: var(--msb-palette-nav-active-bg, var(--msb-palette-action-soft)) !important;\n' +
      '  color: var(--msb-palette-nav-active-text, var(--msb-palette-btn-text, #ffffff)) !important;\n' +
      '  border-top-color: var(--msb-palette-nav-active-text, var(--msb-palette-btn-text, #ffffff)) !important;\n' +
      '}\n' +
      'html[data-msb-appearance] body.profile-page .ig-tab.active i {\n' +
      '  background-color: transparent !important;\n' +
      '  color: inherit !important;\n' +
      '}\n';
    movePaletteStyleToDocumentEnd(style);
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function(){ movePaletteStyleToDocumentEnd(style); });
    }
    window.addEventListener('load', function(){
      movePaletteStyleToDocumentEnd(style);
      setTimeout(function(){ movePaletteStyleToDocumentEnd(style); }, 0);
    });
  }

  function clearPalettePaintStyle(){
    var style = document.getElementById(PALETTE_PAINT_STYLE_ID);
    if (style && style.parentNode) {
      style.parentNode.removeChild(style);
    }
  }

  function rgbaFromHex(hex, alpha){
    var rgb = hexToRgb(hex);
    if (!rgb) return 'rgba(15,23,42,' + alpha + ')';
    return 'rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',' + alpha + ')';
  }

  function applyPaletteTokenFamilies(root, tokens){
    var t = tokens;
    var ddHeadEnd = t.isDark ? mixHex(t.bg, '#000000', 0.2) : mixHex(t.bg, '#000000', 0.12);
    var ddUnread = t.isDark ? mixHex(t.hex, '#ffffff', 0.15) : mixHex(t.hex, '#000000', 0.08);
    var publicAccent = t.actionAccent || accentLinkOn(t.bg, t.hex);
    var publicAccentStrong = t.actionAccentHover || mixHex(publicAccent, t.isDark ? '#ffffff' : '#000000', t.isDark ? 0.14 : 0.12);
    var msgPanel = t.bg;
    var msgPanel2 = t.navHover || t.bg;
    var msgPanel3 = t.dropdownHover || t.navHover || t.bg;
    var plGradient = t.bg;
    var bodyGradient = t.bg;
    var families = {
      '--msb-dd-surface': t.bg,
      '--msb-dd-surface-strong': t.bg,
      '--msb-dd-border': t.borderStrong,
      '--msb-dd-divider': t.border,
      '--msb-dd-text': t.text,
      '--msb-dd-muted': t.muted,
      '--msb-dd-soft': t.muted,
      '--msb-dd-hover': t.dropdownHover || t.navHover,
      '--msb-dd-hover-text': t.hoverText || t.text,
      '--msb-dd-unread': ddUnread,
      '--msb-dd-warn': ensureContrast('#92400e', t.bg, 3),
      '--msb-dd-warn-hover': t.isDark ? 'rgba(245,158,11,.24)' : mixHex(t.hex, '#000000', 0.06),
      '--msb-dd-shadow': t.shadowStrong,
      '--msb-dd-head-bg': t.bg,
      '--msb-dd-head-text': t.text,
      '--msb-rx-surface': t.bg,
      '--msb-rx-border': t.borderStrong,
      '--msb-rx-shadow': t.shadowStrong,
      '--msb-rx-text': t.text,
      '--msg-bg': t.bg,
      '--msg-panel': msgPanel,
      '--msg-panel-2': msgPanel2,
      '--msg-panel-3': msgPanel3,
      '--msg-border': t.borderStrong,
      '--msg-text': t.text,
      '--msg-muted': t.muted,
      '--msg-muted-2': t.muted,
      '--msg-accent': publicAccent,
      '--msg-accent-2': publicAccentStrong,
      '--msg-accent-soft': rgbaFromHex(publicAccent, 0.12),
      '--msg-blue': publicAccent,
      '--public-surface': t.bg,
      '--public-surface-alt': t.bg,
      '--public-surface-strong': t.bg,
      '--public-border': t.border,
      '--public-border-strong': t.borderStrong,
      '--public-text': t.text,
      '--public-muted': t.muted,
      '--public-soft-text': t.muted,
      '--public-topbar-bg': t.bg,
      '--public-topbar-text': t.text,
      '--public-sidebar-bg': t.bg,
      '--public-sidebar-hover': t.navHover,
      '--public-control-bg': t.bg,
      '--public-control-soft': t.bg,
      '--public-control-border': t.borderStrong,
      '--public-control-placeholder': t.placeholder,
      '--public-accent': publicAccent,
      '--public-accent-soft': rgbaFromHex(publicAccent, 0.12),
      '--public-accent-strong': publicAccentStrong,
      '--public-post-card-surface': t.bg,
      '--public-post-card-border': t.border,
      '--pl-text': t.text,
      '--pl-muted': t.muted,
      '--pl-border': t.border,
      '--pl-border-strong': t.borderStrong,
      '--pl-shell': t.bg,
      '--pl-panel': t.bg,
      '--pl-control-bg': t.bg,
      '--pl-control-soft': t.bg,
      '--pl-control-border': t.borderStrong,
      '--pl-control-placeholder': t.placeholder,
      '--pl-tab-shell': t.bg,
      '--pl-tab-active': t.btnBg,
      '--pl-tab-active-text': t.btnText,
      '--pl-action-bg': t.bg,
      '--pl-action-text': t.text,
      '--pl-action-hover': t.navHover,
      '--pl-subscribe-bg': t.btnBg,
      '--pl-subscribe-text': t.btnText,
      '--pl-summary-bg': rgbaFromHex(t.text, 0.06),
      '--pl-row-bg': plGradient,
      '--pl-row-main-bg': plGradient,
      '--pl-card-bg': t.bg,
      '--pl-card-hover': t.navHover,
      '--pl-card-active': t.navHover,
      '--pl-rail-bg': t.bg,
      '--pl-rail-head': t.bg,
      '--pl-empty-bg': plGradient,
      '--pl-shadow': t.shadowStrong,
      '--pl-host': publicAccentStrong,
      '--msb-palette-body-gradient': bodyGradient,
      '--feed-surface': t.bg,
      '--feed-surface-alt': t.bg,
      '--feed-surface-strong': t.bg,
      '--feed-border': t.border,
      '--feed-border-strong': t.borderStrong,
      '--feed-text': t.text,
      '--feed-muted': t.muted,
      '--feed-soft-text': t.muted,
      '--feed-topbar-bg': t.bg,
      '--feed-topbar-text': t.text,
      '--feed-control-bg': t.bg,
      '--feed-control-soft': t.bg,
      '--feed-control-border': t.borderStrong,
      '--feed-control-placeholder': t.placeholder,
      '--feed-accent': publicAccent,
      '--feed-accent-soft': rgbaFromHex(publicAccent, 0.12),
      '--feed-accent-strong': publicAccentStrong,
      '--feed-page-bg': t.bg
    };
    Object.keys(families).forEach(function(prop){
      root.style.setProperty(prop, families[prop]);
    });
  }

  function clearPaletteVars(root){
    var props = [
      '--bg', '--bg-main', '--bg-card', '--bg-sidebar', '--surface', '--surface-2',
      '--text', '--text-primary', '--text-secondary', '--text-muted', '--muted',
      '--border', '--border-color', '--accent', '--org-bg', '--org-accent', '--org-accent-strong', '--org-btn-on-accent',
      '--org-btn-filled-bg', '--org-btn-filled-text',
      '--msb-palette-bg', '--msb-palette-panel', '--msb-palette-surface', '--msb-palette-surface-2',
      '--msb-palette-sidebar', '--msb-palette-header', '--msb-palette-header-text',
      '--msb-palette-nav', '--msb-palette-nav-hover', '--msb-palette-hover-bg', '--msb-palette-surface-hover', '--msb-palette-footer',
      '--msb-palette-accent', '--msb-palette-action', '--msb-palette-action-strong', '--msb-palette-action-soft',
      '--msb-palette-text', '--msb-palette-text-muted',
      '--msb-palette-text-on-surface', '--msb-palette-text-muted-on-surface',
      '--msb-palette-text-on-nav', '--msb-palette-text-on-nav-hover', '--msb-palette-text-on-hover',
      '--msb-palette-nav-active-bg', '--msb-palette-nav-active-text', '--msb-palette-nav-active-icon',
      '--msb-palette-icon', '--msb-palette-icon-on-surface', '--msb-palette-icon-on-header',
      '--msb-palette-icon-on-nav-hover', '--msb-palette-icon-on-hover',
      '--msb-palette-link', '--msb-palette-link-on-surface', '--msb-palette-link-hover',
      '--msb-palette-header-hover', '--msb-palette-header-hover-text',
      '--msb-palette-btn-bg', '--msb-palette-btn-text', '--msb-palette-btn-hover-bg',
      '--msb-palette-btn-secondary-bg', '--msb-palette-btn-secondary-text',
      '--msb-palette-btn-secondary-hover', '--msb-palette-dropdown-hover',
      '--msb-palette-placeholder', '--msb-palette-border-strong', '--msb-palette-active',
      '--msb-palette-icon-hover', '--msb-palette-bubble-me-text',
      '--msb-palette-bubble-bg', '--msb-palette-bubble-text', '--msb-palette-bubble-meta',
      '--msb-palette-bubble-border', '--msb-palette-bubble-reply-bg',
      '--msb-palette-border', '--msb-palette-input-bg',
      '--msb-palette-shadow', '--shadow', '--shadow-strong',
      '--msb-dd-surface', '--msb-dd-surface-strong', '--msb-dd-border', '--msb-dd-divider',
      '--msb-dd-text', '--msb-dd-muted', '--msb-dd-soft', '--msb-dd-hover', '--msb-dd-unread',
      '--msb-dd-warn', '--msb-dd-warn-hover', '--msb-dd-shadow', '--msb-dd-head-bg', '--msb-dd-head-text',
      '--msb-rx-surface', '--msb-rx-border', '--msb-rx-shadow', '--msb-rx-text',
      '--msg-bg', '--msg-panel', '--msg-panel-2', '--msg-panel-3', '--msg-border', '--msg-text',
      '--msg-muted', '--msg-muted-2', '--msg-accent', '--msg-accent-2', '--msg-accent-soft', '--msg-blue',
      '--public-surface', '--public-surface-alt', '--public-surface-strong', '--public-border',
      '--public-border-strong', '--public-text', '--public-muted', '--public-soft-text',
      '--public-topbar-bg', '--public-topbar-text', '--public-sidebar-bg', '--public-sidebar-hover',
      '--public-control-bg', '--public-control-soft', '--public-control-border',
      '--public-control-placeholder', '--public-accent', '--public-accent-soft',
      '--public-accent-strong', '--public-post-card-surface', '--public-post-card-border',
      '--pl-text', '--pl-muted', '--pl-border', '--pl-border-strong', '--pl-shell', '--pl-panel',
      '--pl-control-bg', '--pl-control-soft', '--pl-control-border', '--pl-control-placeholder',
      '--pl-tab-shell', '--pl-tab-active', '--pl-tab-active-text', '--pl-action-bg', '--pl-action-text',
      '--pl-action-hover', '--pl-subscribe-bg', '--pl-subscribe-text', '--pl-summary-bg', '--pl-row-bg',
      '--pl-row-main-bg', '--pl-card-bg', '--pl-card-hover', '--pl-card-active', '--pl-rail-bg',
      '--pl-rail-head', '--pl-empty-bg', '--pl-shadow', '--pl-host', '--msb-palette-body-gradient',
      '--feed-surface', '--feed-surface-alt', '--feed-surface-strong', '--feed-border', '--feed-border-strong',
      '--feed-text', '--feed-muted', '--feed-soft-text', '--feed-topbar-bg', '--feed-topbar-text',
      '--feed-control-bg', '--feed-control-soft', '--feed-control-border', '--feed-control-placeholder',
      '--feed-accent', '--feed-accent-soft', '--feed-accent-strong', '--feed-page-bg', '--blue'
    ];
    props.forEach(function(prop){ root.style.removeProperty(prop); });
    root.removeAttribute('data-msb-appearance');
    root.classList.remove('msb-palette-active', 'msb-palette-unified', 'msb-palette-dark-fg', 'msb-palette-light-fg');
    if (document.body) document.body.classList.remove('msb-palette-active', 'msb-palette-unified', 'msb-palette-dark-fg', 'msb-palette-light-fg');
    clearPaletteStylesheet();
    clearPalettePaintStyle();
  }

  function applyPaletteVars(root, slug){
    var meta = paletteMeta(slug);
    if (!meta || slug === 'system') {
      clearPaletteVars(root);
      return;
    }

    var hex = meta.hex;
    var isDark = !!meta.dark;
    var bg = paletteUnifiedBackground(hex, isDark);
    var surface = bg;
    var surface2 = palettePanelSurface(bg, isDark);
    var sidebar = bg;
    var headerBg = bg;
    var fg = paletteChromaticForeground(hex, bg, isDark);
    var navHover = fg.navHoverBg || fg.hoverBg;
    if (!isDark && contrastRatio(bg, navHover) < 1.14) {
      navHover = mixHex(bg, '#000000', 0.16);
    }
    var headerHover = fg.hoverBg;
    var genericHover = fg.hoverBg;
    var surfaceHover = hoverBgOn(bg, isDark);
    var textOnHover = fg.hoverText || ensureContrast(null, genericHover, 4.5);
    var iconOnHover = fg.hoverIcon || textOnHover;
    var inputBg = bg;
    var shadow = isDark ? '0 10px 18px rgba(0,0,0,.35)' : '0 14px 38px rgba(15,23,42,.06)';
    var shadowStrong = isDark ? '0 14px 24px rgba(0,0,0,.45)' : '0 14px 24px rgba(15,23,42,.10)';

    var text = ensureContrast(fg.text, bg, 4.5);
    var textOnSurface = text;
    var textOnNav = text;
    var muted = ensureContrast(fg.muted, bg, 4.2);
    var mutedOnSurface = muted;
    var icon = ensureContrast(fg.icon, bg, 4.5);
    var iconOnSurface = fg.icon;
    var iconOnHeader = fg.icon;
    var navActiveBg = fg.navActiveBg || mixHex(bg, '#000000', 0.12);
    var navActiveText = fg.navActiveText || textOnNav;
    var navActiveIcon = fg.navActiveIcon || icon;
    if (contrastRatio(navActiveText, navActiveBg) < 4.5) {
      navActiveText = ensureContrast(null, navActiveBg, 4.5);
    }
    if (relativeLuminance(navActiveBg) < 0.42 && relativeLuminance(navActiveText) > 0.42) {
      navActiveText = '#f8fafc';
    } else if (relativeLuminance(navActiveBg) > 0.58 && relativeLuminance(navActiveText) < 0.58) {
      navActiveText = '#0f172a';
    }
    navActiveIcon = ensureContrast(navActiveIcon, navActiveBg, 3);
    if (contrastRatio(navActiveIcon, navActiveBg) < 3) {
      navActiveIcon = navActiveText;
    }
    var textOnNavHover = fg.navHoverText || textOnHover;
    var iconOnNavHover = fg.navHoverIcon || iconOnHover;
    var headerText = fg.text;
    var headerHoverText = fg.linkHover;
    var actionAccent = paletteActionAccent(hex, bg, isDark);
    var actionAccentHover = isDark ? mixHex(actionAccent, '#ffffff', 0.14) : mixHex(actionAccent, '#000000', 0.12);
    var link = actionAccent;
    var linkOnSurface = actionAccent;
    var linkHover = actionAccentHover;
    var border = fg.border;
    var borderStrong = fg.borderStrong;
    var btnBg = fg.btnBg || btnBgOn(hex, bg, isDark);
    var btnText = fg.btnText || ensureContrast(null, btnBg, 4.5);
    if (contrastRatio(btnText, btnBg) < 4.5) {
      btnText = ensureContrast(null, btnBg, 4.5);
    }
    if (relativeLuminance(btnBg) < 0.42 && relativeLuminance(btnText) > 0.42) {
      btnText = '#f8fafc';
    } else if (relativeLuminance(btnBg) > 0.58 && relativeLuminance(btnText) < 0.58) {
      btnText = '#0f172a';
    }
    if (contrastRatio(btnText, btnBg) < 4.5) {
      btnText = ensureContrast(null, btnBg, 4.5);
    }
    var btnHoverBg = fg.btnHoverBg || (isDark ? mixHex(btnBg, '#ffffff', 0.12) : mixHex(btnBg, '#000000', 0.1));
    var btnSecondaryBg = bg;
    var btnSecondaryText = fg.text;
    var btnSecondaryHover = genericHover;
    var dropdownHover = genericHover;
    var activeColor = actionAccentHover;
    var placeholder = fg.placeholder;
    var bubbleMeText = fg.btnText;
    var incomingBubble = paletteIncomingBubbleColors(hex, bg, isDark, fg);

    root.setAttribute('data-msb-appearance', slug);
    root.classList.add('msb-palette-active', 'msb-palette-unified');
    root.classList.remove('msb-palette-dark-fg', 'msb-palette-light-fg');
    root.classList.add(fg.mode === 'dark-fg' ? 'msb-palette-dark-fg' : 'msb-palette-light-fg');
    if (document.body) {
      document.body.classList.add('msb-palette-active', 'msb-palette-unified');
      document.body.classList.remove('msb-palette-dark-fg', 'msb-palette-light-fg');
      document.body.classList.add(fg.mode === 'dark-fg' ? 'msb-palette-dark-fg' : 'msb-palette-light-fg');
    }

    root.style.setProperty('--msb-palette-bg', bg);
    root.style.setProperty('--msb-palette-panel', surface);
    root.style.setProperty('--msb-palette-surface', surface);
    root.style.setProperty('--msb-palette-surface-2', surface2);
    root.style.setProperty('--msb-palette-sidebar', sidebar);
    root.style.setProperty('--msb-palette-header', headerBg);
    root.style.setProperty('--msb-palette-header-text', headerText);
    root.style.setProperty('--msb-palette-nav', sidebar);
    root.style.setProperty('--msb-palette-nav-hover', navHover);
    root.style.setProperty('--msb-palette-hover-bg', genericHover);
    root.style.setProperty('--msb-palette-surface-hover', surfaceHover);
    root.style.setProperty('--msb-palette-text-on-hover', textOnHover);
    root.style.setProperty('--msb-palette-footer', surface);
    root.style.setProperty('--msb-palette-accent', hex);
    root.style.setProperty('--msb-palette-action', actionAccent);
    root.style.setProperty('--msb-palette-action-strong', actionAccentHover);
    root.style.setProperty('--msb-palette-action-soft', rgbaFromHex(actionAccent, 0.14));
    root.style.setProperty('--blue', actionAccent);
    root.style.setProperty('--msb-palette-text', text);
    root.style.setProperty('--msb-palette-text-muted', muted);
    root.style.setProperty('--msb-palette-text-on-surface', textOnSurface);
    root.style.setProperty('--msb-palette-text-muted-on-surface', mutedOnSurface);
    root.style.setProperty('--msb-palette-text-on-nav', textOnNav);
    root.style.setProperty('--msb-palette-text-on-nav-hover', textOnNavHover);
    root.style.setProperty('--msb-palette-nav-active-bg', navActiveBg);
    root.style.setProperty('--msb-palette-nav-active-text', navActiveText);
    root.style.setProperty('--msb-palette-nav-active-icon', navActiveIcon);
    root.style.setProperty('--msb-palette-icon', icon);
    root.style.setProperty('--msb-palette-icon-on-surface', iconOnSurface);
    root.style.setProperty('--msb-palette-icon-on-header', iconOnHeader);
    root.style.setProperty('--msb-palette-border', border);
    root.style.setProperty('--msb-palette-border-strong', borderStrong);
    root.style.setProperty('--msb-palette-link', link);
    root.style.setProperty('--msb-palette-link-on-surface', linkOnSurface);
    root.style.setProperty('--msb-palette-link-hover', linkHover);
    root.style.setProperty('--msb-palette-icon-hover', iconOnHover);
    root.style.setProperty('--msb-palette-icon-on-hover', iconOnHover);
    root.style.setProperty('--msb-palette-icon-on-nav-hover', iconOnNavHover);
    root.style.setProperty('--msb-palette-header-hover', headerHover);
    root.style.setProperty('--msb-palette-header-hover-text', headerHoverText);
    root.style.setProperty('--msb-palette-btn-bg', btnBg);
    root.style.setProperty('--msb-palette-btn-text', btnText);
    root.style.setProperty('--msb-palette-btn-hover-bg', btnHoverBg);
    root.style.setProperty('--msb-palette-btn-secondary-bg', btnSecondaryBg);
    root.style.setProperty('--msb-palette-btn-secondary-text', btnSecondaryText);
    root.style.setProperty('--msb-palette-btn-secondary-hover', btnSecondaryHover);
    root.style.setProperty('--msb-palette-dropdown-hover', dropdownHover);
    root.style.setProperty('--msb-palette-placeholder', placeholder);
    root.style.setProperty('--msb-palette-active', activeColor);
    root.style.setProperty('--msb-palette-bubble-me-text', bubbleMeText);
    root.style.setProperty('--msb-palette-bubble-bg', incomingBubble.bg);
    root.style.setProperty('--msb-palette-bubble-text', incomingBubble.text);
    root.style.setProperty('--msb-palette-bubble-meta', incomingBubble.meta);
    root.style.setProperty('--msb-palette-bubble-border', incomingBubble.border);
    root.style.setProperty('--msb-palette-bubble-reply-bg', incomingBubble.replyBg);
    root.style.setProperty('--msb-palette-input-bg', inputBg);
    root.style.setProperty('--msb-palette-shadow', shadow);

    root.style.setProperty('--bg', bg);
    root.style.setProperty('--bg-main', bg);
    root.style.setProperty('--bg-card', surface);
    root.style.setProperty('--bg-sidebar', sidebar);
    root.style.setProperty('--surface', surface);
    root.style.setProperty('--surface-2', surface2);
    root.style.setProperty('--text', text);
    root.style.setProperty('--text-primary', text);
    root.style.setProperty('--text-secondary', mutedOnSurface);
    root.style.setProperty('--text-muted', muted);
    root.style.setProperty('--muted', muted);
    root.style.setProperty('--border', border);
    root.style.setProperty('--border-color', border);
    root.style.setProperty('--accent', hex);
    root.style.setProperty('--org-bg', bg);
    root.style.setProperty('--org-accent', btnBg);
    root.style.setProperty('--org-accent-strong', btnHoverBg);
    root.style.setProperty('--org-btn-on-accent', btnText);
    root.style.setProperty('--org-btn-filled-bg', btnBg);
    root.style.setProperty('--org-btn-filled-text', btnText);
    if (document.body) {
      document.body.style.setProperty('--org-btn-filled-bg', btnBg);
      document.body.style.setProperty('--org-btn-filled-text', btnText);
      document.body.style.setProperty('--org-btn-on-accent', btnText);
    }
    root.style.setProperty('--shadow', shadow);
    root.style.setProperty('--shadow-strong', shadowStrong);
    root.style.setProperty('--feed-surface', bg);
    root.style.setProperty('--feed-surface-alt', bg);
    root.style.setProperty('--feed-surface-strong', bg);
    root.style.setProperty('--feed-border', border);
    root.style.setProperty('--feed-border-strong', borderStrong);
    root.style.setProperty('--feed-text', text);
    root.style.setProperty('--feed-muted', muted);
    root.style.setProperty('--feed-soft-text', mutedOnSurface);
    root.style.setProperty('--feed-topbar-bg', bg);
    root.style.setProperty('--feed-topbar-text', text);
    root.style.setProperty('--feed-control-bg', bg);
    root.style.setProperty('--feed-control-soft', bg);
    root.style.setProperty('--feed-control-border', borderStrong);
    root.style.setProperty('--feed-control-placeholder', placeholder);
    root.style.setProperty('--feed-accent', actionAccent);
    root.style.setProperty('--feed-accent-soft', rgbaFromHex(actionAccent, 0.12));
    root.style.setProperty('--feed-accent-strong', actionAccent);

    applyPaletteTokenFamilies(root, {
      hex: hex,
      isDark: isDark,
      bg: bg,
      surface: surface,
      surface2: surface2,
      sidebar: sidebar,
      headerBg: headerBg,
      headerText: headerText,
      navHover: navHover,
      hoverText: textOnHover,
      inputBg: inputBg,
      text: text,
      textOnSurface: textOnSurface,
      muted: muted,
      mutedOnSurface: mutedOnSurface,
      border: border,
      borderStrong: borderStrong,
      btnBg: btnBg,
      btnText: btnText,
      dropdownHover: dropdownHover,
      placeholder: placeholder,
      shadowStrong: shadowStrong,
      actionAccent: actionAccent,
      actionAccentHover: actionAccentHover
    });

    ensurePaletteStylesheet();
    ensurePalettePaintStyle();
  }

  function paletteUsesDarkChrome(slug, meta){
    if (!meta) return false;
    if (typeof meta.darkChrome === 'boolean') return meta.darkChrome;
    return !!meta.dark;
  }

  function resolveAppearanceMode(prefs){
    return normalizeAppearanceMode(prefs.appearanceMode || prefs.manualMode || 'dark');
  }

  function isNamedPaletteMode(mode){
    mode = normalizeAppearanceMode(mode);
    if (mode === 'system' || mode === 'light' || mode === 'dark') return false;
    return !!(window.__MSB_APPEARANCE_PALETTES && window.__MSB_APPEARANCE_PALETTES[mode]);
  }

  function applyBuiltInDarkThemeVars(root){
    if (!root) return;
    var bg = appearanceDarkBg();
    var chrome = appearanceDarkChrome();
    root.style.setProperty('--msb-dark-auto-chrome', chrome);
    root.style.setProperty('--msb-palette-bg', bg);
    root.style.setProperty('--msb-palette-panel', bg);
    root.style.setProperty('--msb-palette-text', chrome);
    root.style.setProperty('--msb-palette-text-on-nav', chrome);
    root.style.setProperty('--msb-palette-text-muted', ensureContrast('#cbd5e1', bg, 4.2));
    root.style.setProperty('--msb-palette-icon', chrome);
    root.style.setProperty('--msb-palette-link', chrome);
    root.style.setProperty('--msb-palette-link-hover', chrome);
    root.style.setProperty('--msb-palette-border', 'rgba(255,255,255,.12)');
    root.style.setProperty('--msb-palette-border-strong', 'rgba(255,255,255,.16)');
    root.style.setProperty('--msb-palette-hover-bg', 'rgba(255,255,255,.08)');
    root.style.setProperty('--msb-palette-nav-hover', '#2f3a4a');
    root.style.setProperty('--msb-palette-text-on-nav-hover', '#e8edf5');
    root.style.setProperty('--msb-palette-text-on-hover', '#e8edf5');
    root.style.setProperty('--msb-palette-icon-on-nav-hover', '#e8edf5');
    root.style.setProperty('--msb-palette-icon-on-hover', '#e8edf5');
    root.style.setProperty('--msb-palette-nav-active-bg', 'rgba(255,255,255,0.12)');
    root.style.setProperty('--msb-palette-nav-active-text', '#ffffff');
    root.style.setProperty('--msb-palette-nav-active-icon', '#ffffff');
    root.style.setProperty('--msb-palette-surface-2', bg);
    root.style.setProperty('--studio-text', chrome);
    root.style.setProperty('--studio-muted', chrome);
    root.style.setProperty('--text-primary', chrome);
    root.style.setProperty('--text-secondary', chrome);
    root.style.setProperty('--text-muted', chrome);
    root.style.setProperty('--feed-text', chrome);
    root.style.setProperty('--feed-muted', chrome);
    root.style.setProperty('--public-text', chrome);
    root.style.setProperty('--public-muted', chrome);
    root.style.setProperty('--org-accent', chrome);
    root.style.setProperty('--org-link', chrome);
    root.style.setProperty('--org-icon', chrome);
    root.style.setProperty('--bg', bg);
    root.style.setProperty('--bg-main', bg);
    root.style.setProperty('--feed-page-bg', bg);
  }

  function applyBuiltInLightThemeVars(root){
    if (!root) return;
    var bg = appearanceLightBg();
    var text = '#0f172a';
    var muted = ensureContrast('#475569', bg, 4.5);
    var soft = ensureContrast('#334155', bg, 4.5);
    var border = 'rgba(15,23,42,.12)';
    var borderStrong = 'rgba(15,23,42,.16)';
    var surface = bg;
    var surfaceAlt = '#eef3fb';
    var accent = '#2563eb';

    root.style.setProperty('--msb-palette-bg', bg);
    root.style.setProperty('--msb-palette-panel', surface);
    root.style.setProperty('--msb-palette-surface', surface);
    root.style.setProperty('--msb-palette-surface-2', surface);
    root.style.setProperty('--msb-palette-text', text);
    root.style.setProperty('--msb-palette-text-on-nav', text);
    root.style.setProperty('--msb-palette-text-muted', muted);
    root.style.setProperty('--msb-palette-icon', text);
    root.style.setProperty('--msb-palette-border', border);
    root.style.setProperty('--msb-palette-border-strong', borderStrong);
    root.style.setProperty('--msb-palette-link', accent);
    root.style.setProperty('--msb-palette-hover-bg', 'rgba(15,23,42,.06)');
    root.style.setProperty('--msb-palette-action', accent);
    root.style.setProperty('--msb-palette-action-soft', 'rgba(37,99,235,.10)');
    root.style.setProperty('--msb-palette-action-strong', '#1d4ed8');
    root.style.setProperty('--msb-palette-btn-bg', accent);
    root.style.setProperty('--msb-palette-btn-text', ensureContrast(null, accent, 4.5));
    root.style.setProperty('--msb-palette-btn-hover-bg', '#1d4ed8');
    root.style.setProperty('--org-accent', accent);
    root.style.setProperty('--org-accent-strong', '#1d4ed8');
    root.style.setProperty('--org-btn-on-accent', ensureContrast(null, accent, 4.5));
    root.style.setProperty('--org-btn-filled-bg', accent);
    root.style.setProperty('--org-btn-filled-text', ensureContrast(null, accent, 4.5));
    if (document.body) {
      document.body.style.setProperty('--org-btn-filled-bg', accent);
      document.body.style.setProperty('--org-btn-filled-text', ensureContrast(null, accent, 4.5));
      document.body.style.setProperty('--org-btn-on-accent', ensureContrast(null, accent, 4.5));
    }
    root.style.setProperty('--bg', bg);
    root.style.setProperty('--bg-main', bg);
    root.style.setProperty('--bg-card', surface);
    root.style.setProperty('--text-primary', text);
    root.style.setProperty('--text-secondary', soft);
    root.style.setProperty('--text-muted', muted);
    root.style.setProperty('--feed-page-bg', bg);
    root.style.setProperty('--feed-surface', bg);
    root.style.setProperty('--feed-surface-alt', surfaceAlt);
    root.style.setProperty('--feed-surface-strong', '#eef3f8');
    root.style.setProperty('--feed-border', border);
    root.style.setProperty('--feed-border-strong', borderStrong);
    root.style.setProperty('--feed-text', text);
    root.style.setProperty('--feed-muted', muted);
    root.style.setProperty('--feed-soft-text', soft);
    root.style.setProperty('--feed-topbar-bg', bg);
    root.style.setProperty('--feed-topbar-text', text);
    root.style.setProperty('--feed-control-bg', surface);
    root.style.setProperty('--feed-control-soft', surfaceAlt);
    root.style.setProperty('--feed-control-border', borderStrong);
    root.style.setProperty('--feed-control-placeholder', muted);
    root.style.setProperty('--feed-accent', accent);
    root.style.setProperty('--feed-accent-soft', 'rgba(37,99,235,.10)');
    root.style.setProperty('--feed-accent-strong', '#1d4ed8');
    root.style.setProperty('--public-surface', bg);
    root.style.setProperty('--public-surface-alt', surfaceAlt);
    root.style.setProperty('--public-surface-strong', surfaceAlt);
    root.style.setProperty('--public-post-card-surface', bg);
    root.style.setProperty('--public-border', border);
    root.style.setProperty('--public-border-strong', borderStrong);
    root.style.setProperty('--public-text', text);
    root.style.setProperty('--public-muted', muted);
    root.style.setProperty('--public-soft-text', soft);
    root.style.setProperty('--public-topbar-bg', bg);
    root.style.setProperty('--public-topbar-text', text);
    root.style.setProperty('--public-sidebar-bg', 'rgba(255,255,255,.92)');
    root.style.setProperty('--public-sidebar-hover', surfaceAlt);
    root.style.setProperty('--public-control-bg', surface);
    root.style.setProperty('--public-control-soft', surfaceAlt);
    root.style.setProperty('--public-control-border', borderStrong);
    root.style.setProperty('--public-control-placeholder', muted);
    root.style.setProperty('--public-accent', accent);
    root.style.setProperty('--public-accent-soft', 'rgba(37,99,235,.10)');
    root.style.setProperty('--public-accent-strong', '#1d4ed8');
  }

  var BUILTIN_PAINT_STYLE_ID = 'msb-builtin-theme-paint';

  var BUILTIN_PAINT_SELECTORS = [
    'body.profile-page', 'body.profile-page .sh-mainpanel', 'body.profile-page .sh-pagebody',
    'body.profile-page .ig-wrap', 'body.profile-page .ig-profile-shell', 'body.profile-page .ig-tabs',
    'body.profile-page .ig-profile-head', 'body.profile-page .ig-profile-scroll',
    'body.profile-page .ig-highlights', 'body.profile-page .ig-avatar', 'body.profile-page .ig-top',
    'body.profile-page .profile-panel', 'body.profile-page .profile-panel.active',
    'body.profile-page #profilePostsFeed', 'body.profile-page #profilePostsFeed.mf-feed',
    'body.profile-page #profilePostsFeed .mf-card', 'body.profile-page .mf-feed-empty',
    'body.profile-page .gear-wrap', 'body.profile-page .gear-shell',
    'body.profile-page .gear-sidebar', 'body.profile-page .gear-sidebar-head', 'body.profile-page .gear-main',
    'body.profile-page .gear-nav', 'body.profile-page .gear-detail-panel', 'body.profile-page .gear-detail-head',
    'body.profile-page .gear-detail-body', 'body.profile-page .gear-detail-empty', 'body.profile-page .gear-search',
    'body.profile-page .gear-note', 'body.profile-page .gear-control',
    'body.shop-page', 'body.shop-page .sh-mainpanel', 'body.shop-page .sh-pagebody',
    'body.shop-page .shop-page-shell', 'body.shop-page .shop-market-card', 'body.shop-page .cart-row', 'body.shop-page .feed-left-rail',
    'body.shop-page .feed-left-nav', 'body.shop-page .shop-nav-filters', 'body.shop-page .shop-brand-nav',
    'body.shop-page .ig-feed-header',
    'body.org-app', 'body.org-app .sh-mainpanel', 'body.org-app .sh-pagebody',
    'body.org-app .sh-sideleft-menu', 'body.org-app .org-sideleft-scroll', 'body.org-app .commerce-page',
    'body.org-app .commerce-kpi', 'body.org-app .commerce-panel', 'body.org-app .commerce-action-tile',
    'body.org-app .commerce-panel-head', 'body.org-app .commerce-brand-system-panel',
    'body.org-app .commerce-int-item', 'body.org-app .commerce-channel', 'body.org-app .commerce-table-wrap',
    'body.org-app .sales-management-metric', 'body.org-app .sales-management-table-wrap',
    'body.org-app .card', 'body.org-app .card-body', 'body.org-app .card-header',
    'body.org-app .feed-sidebar', 'body.org-app .feed-card', 'body.org-app .feed-toolbar',
    'body.org-app .feed-layout', 'body.org-app .feed-main', 'body.org-app .rows-scroll',
    'body.org-app .sidebar-head', 'body.org-app .sidebar-list',
    'body.org-app .feed-post-detail', 'body.org-app .feed-compose', 'body.org-app .comment-item',
    'body.org-app .sidebar-search', 'body.org-app .feed-compose-input',
    'body.org-app .compose-card', 'body.org-app .compose-intro', 'body.org-app .compose-preview',
    'body.org-app .actions-fixed', 'body.org-app .card-body-fixed',
    'body.org-app .chat-shell', 'body.org-app .chat-left', 'body.org-app .chat-right',
    'body.org-app .fixed-card', 'body.org-app .fixed-head', 'body.org-app .fixed-body',
    'body.org-app .members-list-wrap', 'body.org-app .members-search', 'body.org-app .members-scroll',
    'body.org-app .conv-split', 'body.org-app .conv-main', 'body.org-app .conv-topbar',
    'body.org-app .conv-history', 'body.org-app .history-card', 'body.org-app .history-head',
    'body.org-app .history-search', 'body.org-app .history-scroll', 'body.org-app .msg-scroll',
    'body.org-app .composer-fixed', 'body.org-app .search-bar', 'body.org-app .list-group-item',
    'body.org-app .org-sideleft-top', 'body.org-app .org-sideleft-bottom',
    'body.org-app .sh-logopanel', 'body.org-app .sh-headpanel',
    'body.org-app .org-pill', 'body.org-app .ig-feed-account-badge',
    '#ttLeftbarOverlays .tt-profile-wrap', '#ttLeftbarOverlays .tt-profile-head', '#ttLeftbarOverlays .tt-profile-body',
    '.msb-profile-door-host .tt-profile-wrap', '.msb-profile-door-host .tt-profile-head', '.msb-profile-door-host .tt-profile-body'
  ].join(',\n');

  var BUILTIN_DOOR_PROFILE_TEXT_SELECTORS = [
    '#ttLeftbarOverlays .tt-profile-head .title', '#ttLeftbarOverlays .tt-profile-name',
    '#ttLeftbarOverlays .tt-profile-badge', '#ttLeftbarOverlays .tt-profile-nav a',
    '.msb-profile-door-host .tt-profile-head .title', '.msb-profile-door-host .tt-profile-name',
    '.msb-profile-door-host .tt-profile-badge', '.msb-profile-door-host .tt-profile-nav a'
  ].join(',\n');

  var BUILTIN_DOOR_PROFILE_MUTED_SELECTORS = [
    '#ttLeftbarOverlays .tt-profile-email', '#ttLeftbarOverlays .tt-profile-code',
    '.msb-profile-door-host .tt-profile-email', '.msb-profile-door-host .tt-profile-code'
  ].join(',\n');

  var BUILTIN_DOOR_PROFILE_ICON_SELECTORS = [
    '#ttLeftbarOverlays .tt-profile-nav a .icon', '#ttLeftbarOverlays .tt-profile-wrap .tt-close i',
    '.msb-profile-door-host .tt-profile-nav a .icon', '.msb-profile-door-host .tt-profile-wrap .tt-close i'
  ].join(',\n');

  var BUILTIN_GEAR_FG_SELECTORS = [
    'body.profile-page .gear-sidebar-title', 'body.profile-page .gear-detail-title',
    'body.profile-page .gear-nav-section-toggle', 'body.profile-page .gear-nav-section-label',
    'body.profile-page .gear-nav-item', 'body.profile-page .gear-detail-control-label',
    'body.profile-page .gear-chip', 'body.profile-page .gear-tag', 'body.profile-page .gear-control',
    'body.profile-page .gear-detail-icon', 'body.profile-page .gear-nav-section-icon'
  ].join(',\n');

  var BUILTIN_HREF_TEXT_SELECTORS = [
    'a:not(.btn):not(.btn-primary):not(.btn-success):not(.btn-outline-primary):not(.btn-outline-secondary):not(.btn-outline-success):not(.ch-btn-primary):not(.ch-btn-ghost):not(.gear-detail-open-btn):not(.feed-ig-logo):not(.feed-ig-btn):not(.messages-shell-tab)',
    '.nav-link:not(.active)', '.feed-ig-link', '.dropdown-item', '.dropdown-link',
    '.dropdown-menu-link', '.bestchat-menu-item', '.dropdown-bestnoti-item',
    'body.org-app a:not(.btn):not(.btn-primary):not(.btn-success):not(.ch-btn-primary):not(.ch-btn-ghost):not(.feed-tab-link)',
    'body.org-app .nav-link:not(.active)', 'body.org-app .sh-icon-link'
  ].join(',\n');

  var BUILTIN_HREF_BTN_SELECTORS = [
    'a.btn', 'a.btn-primary', 'a.btn-success', 'a.btn-outline-primary', 'a.btn-outline-secondary',
    'a.btn-outline-success', 'a.ch-btn-primary', 'a.ch-btn-ghost',
    'body.profile-page a.gear-detail-open-btn', 'body.org-app a.btn',
    'body.org-app a.btn-primary', 'body.org-app a.btn-success', 'body.org-app a.ch-btn-primary',
    'body.org-app a.ch-btn-ghost', 'body.org-app a.feed-tab-link.active',
    'body.org-app .posts-tabs a.active', 'body.org-app .feed-tabs a.active',
    'a.feed-tab-link.active'
  ].join(',\n');

  var BUILTIN_HREF_TEXT_HOVER_SELECTORS = [
    'a:not(.btn):not(.btn-primary):not(.btn-success):not(.btn-outline-primary):not(.btn-outline-secondary):not(.btn-outline-success):not(.ch-btn-primary):not(.ch-btn-ghost):not(.gear-detail-open-btn):not(.feed-ig-logo):not(.feed-ig-btn):not(.messages-shell-tab):hover',
    'a:not(.btn):not(.btn-primary):not(.btn-success):not(.btn-outline-primary):not(.btn-outline-secondary):not(.btn-outline-success):not(.ch-btn-primary):not(.ch-btn-ghost):not(.gear-detail-open-btn):not(.feed-ig-logo):not(.feed-ig-btn):not(.messages-shell-tab):focus',
    '.nav-link:hover', '.nav-link:focus', '.feed-ig-link:hover', '.feed-ig-link:focus',
    '.dropdown-item:hover', '.dropdown-link:hover', '.dropdown-menu-link:hover',
    'body.org-app a:not(.btn):not(.btn-primary):not(.btn-success):not(.ch-btn-primary):not(.ch-btn-ghost):not(.feed-tab-link):hover',
    'body.org-app .nav-link:hover', 'body.org-app .sh-icon-link:hover'
  ].join(',\n');

  var BUILTIN_HREF_BTN_HOVER_SELECTORS = [
    'a.btn:hover', 'a.btn:focus', 'a.btn-primary:hover', 'a.btn-primary:focus',
    'a.btn-success:hover', 'a.btn-success:focus', 'a.ch-btn-primary:hover', 'a.ch-btn-ghost:hover',
    'body.profile-page a.gear-detail-open-btn:hover', 'body.profile-page a.gear-detail-open-btn:focus',
    'body.org-app a.btn:hover', 'body.org-app a.btn-primary:hover', 'body.org-app a.ch-btn-primary:hover',
    'body.org-app a.feed-tab-link.active:hover', 'a.feed-tab-link.active:hover'
  ].join(',\n');

  var BUILTIN_HREF_BTN_ICON_SELECTORS = [
    'body.profile-page a.gear-detail-open-btn i', 'body.profile-page a.gear-detail-open-btn .icon',
    'body.profile-page a.gear-detail-open-btn [class*="ion-"]',
    'a.btn i', 'a.btn-primary i', 'a.btn-success i', 'a.ch-btn-primary i',
    'body.org-app a.btn i', 'body.org-app a.btn-primary i', 'body.org-app a.ch-btn-primary i'
  ].join(',\n');

  var BUILTIN_BTN_FILLED_SELECTORS = [
    '.btn-primary', 'button.btn-primary', 'a.btn-primary',
    '.btn-success', 'button.btn-success', 'a.btn-success',
    '.btn-info', 'button.btn-info', 'a.btn-info',
    '.ch-btn-primary', 'button.ch-btn-primary', 'a.ch-btn-primary',
    'body.profile-page .gear-detail-open-btn', 'body.profile-page .gear-upload-btn',
    'body.profile-page .about-edit-btn', 'body.profile-page .profile-shop-buy-btn',
    'body.profile-page .ig-gallery-search button',
    'body.org-app .btn-primary', 'body.org-app button.btn-primary', 'body.org-app a.btn-primary',
    'body.org-app .btn-success', 'body.org-app a.btn-success',
    'body.org-app .ch-btn-primary', 'body.org-app button.ch-btn-primary', 'body.org-app a.ch-btn-primary',
    'body.org-app .posts-tabs a.active', 'body.org-app .feed-tabs a.active',
    'a.feed-tab-link.active', 'body.org-app a.feed-tab-link.active'
  ].join(',\n');

  var BUILTIN_BTN_SURFACE_SELECTORS = [
    '.btn:not(.btn-primary):not(.btn-success):not(.btn-info)',
    'button.btn:not(.btn-primary):not(.btn-success):not(.btn-info)',
    'a.btn:not(.btn-primary):not(.btn-success):not(.btn-info)',
    '.btn-light', '.btn-white', '.btn-soft', 'button.btn-light', 'a.btn-light',
    '.btn-outline-primary', '.btn-outline-secondary', '.btn-outline-success',
    '.btn-outline-info', '.btn-outline-danger',
    'button.btn-outline-primary', 'button.btn-outline-secondary', 'button.btn-outline-success',
    'a.btn-outline-primary', 'a.btn-outline-secondary', 'a.btn-outline-success',
    '.ch-btn-ghost', 'button.ch-btn-ghost', 'a.ch-btn-ghost',
    '.ig-btn', '.feed-ig-btn', '.action-btn', '.topicon-btn', '.yt-icon-btn',
    '.search-btn', '.btn-search', '.iconbtn', '.tt-iconbtn',
    '.messages-shell-action-btn', '.messages-shell-tab:not(.active)',
    'body.profile-page .gear-nav-item',
    'body.profile-page .ig-btn',
    'body.org-app .btn:not(.btn-primary):not(.btn-success)',
    'body.org-app button.btn', 'body.org-app a.btn:not(.btn-primary):not(.btn-success)',
    'body.org-app .btn-outline-primary', 'body.org-app .btn-outline-secondary',
    'body.org-app .btn-outline-success', 'body.org-app .pin-btn',
    'body.org-app .sidebar-refresh-btn', 'body.org-app .btn-close-x',
    'body.org-app .ch-btn-ghost', 'body.org-app button.ch-btn-ghost'
  ].join(',\n');

  var BUILTIN_BTN_FILLED_HOVER_SELECTORS = [
    '.btn-primary:hover', '.btn-primary:focus', 'button.btn-primary:hover', 'a.btn-primary:hover',
    '.btn-success:hover', '.btn-success:focus', 'button.btn-success:hover', 'a.btn-success:hover',
    '.ch-btn-primary:hover', '.ch-btn-primary:focus', 'button.ch-btn-primary:hover', 'a.ch-btn-primary:hover',
    'body.profile-page .gear-detail-open-btn:hover', 'body.profile-page .gear-detail-open-btn:focus',
    'body.profile-page .gear-upload-btn:hover', 'body.profile-page .gear-upload-btn:focus',
    'body.profile-page .about-edit-btn:hover', 'body.profile-page .profile-shop-buy-btn:hover',
    'body.org-app .btn-primary:hover', 'body.org-app a.btn-primary:hover',
    'body.org-app .ch-btn-primary:hover', 'body.org-app button.ch-btn-primary:hover',
    'body.org-app .posts-tabs a.active:hover', 'body.org-app .feed-tabs a.active:hover'
  ].join(',\n');

  var BUILTIN_BTN_SURFACE_HOVER_SELECTORS = [
    '.btn:not(.btn-primary):not(.btn-success):hover', 'button.btn:hover', 'a.btn:hover',
    '.btn-light:hover', '.btn-white:hover', '.btn-soft:hover',
    '.btn-outline-primary:hover', '.btn-outline-secondary:hover', '.btn-outline-success:hover',
    '.ch-btn-ghost:hover', '.ig-btn:hover', '.feed-ig-btn:hover', '.action-btn:hover',
    '.topicon-btn:hover', '.yt-icon-btn:hover', '.search-btn:hover', '.iconbtn:hover',
    '.messages-shell-action-btn:hover', '.messages-shell-tab:not(.active):hover',
    'body.profile-page .gear-nav-item:hover',
    'body.org-app .btn:hover', 'body.org-app button.btn:hover', 'body.org-app a.btn:hover',
    'body.org-app .btn-outline-primary:hover', 'body.org-app .btn-outline-secondary:hover',
    'body.org-app .ch-btn-ghost:hover', 'body.org-app .pin-btn:hover',
    'body.org-app .sidebar-refresh-btn:hover', 'body.org-app .btn-close-x:hover'
  ].join(',\n');

  var BUILTIN_BTN_ICON_SELECTORS = [
    '.btn-primary i', '.btn-primary .icon', '.btn-primary [class*="ion-"]', '.btn-primary .fa',
    'button.btn-primary i', 'a.btn-primary i', '.btn-success i', 'a.btn-success i',
    '.ch-btn-primary i', 'a.ch-btn-primary i', 'button.ch-btn-primary i',
    'body.profile-page .gear-detail-open-btn i', 'body.profile-page .gear-detail-open-btn .icon',
    'body.profile-page .gear-detail-open-btn [class*="ion-"]',
    'body.profile-page .gear-upload-btn',
    'body.profile-page .about-edit-btn i', 'body.profile-page .profile-shop-buy-btn',
    '.ig-btn i', '.feed-ig-btn i', '.action-btn i', '.btn i', '.btn .icon', 'button.btn i',
    'body.org-app .btn-primary i', 'body.org-app .btn i', 'body.org-app button.btn i',
    'body.org-app .ch-btn-primary i', 'body.org-app .ch-btn-ghost i'
  ].join(',\n');

  var BUILTIN_BTN_FILLED_ICON_SELECTORS = [
    '.btn-primary i', '.btn-primary .icon', '.btn-primary [class*="ion-"]', '.btn-primary .fa',
    'button.btn-primary i', 'a.btn-primary i', '.btn-success i', 'a.btn-success i',
    '.ch-btn-primary i', 'a.ch-btn-primary i', 'button.ch-btn-primary i',
    'body.profile-page .gear-detail-open-btn i', 'body.profile-page .gear-detail-open-btn .icon',
    'body.profile-page .gear-detail-open-btn [class*="ion-"]',
    'body.org-app .btn-primary i', 'body.org-app a.btn-primary i', 'body.org-app .ch-btn-primary i'
  ].join(',\n');

  var BUILTIN_SPAN_SURFACE_SELECTORS = [
    'body.profile-page .gear-tag', 'span.gear-tag', 'span.profile-account-badge',
    '.profile-account-badge', 'span.badge', '.badge', '.badge-light', 'span.feed-badge',
    '.feed-badge', 'span.stat-pill', '.stat-pill', 'span.commerce-pill', '.commerce-pill',
    'span.commerce-order-badge', '.commerce-order-badge', 'span.commerce-brand-card-badge',
    '.commerce-brand-card-badge', 'span.pv-pill', '.pv-pill', 'span.pv-likepill',
    '.pv-likepill', 'span.sidebar-pill', '.sidebar-pill', 'span.msg-pill', '.msg-pill',
    'span.msg-reaction-pill', '.msg-reaction-pill', 'span.live-modal-badge',
    '.live-modal-badge', 'span.sidebar-chip', '.sidebar-chip', 'span.ig-profile-love-count',
    '.ig-profile-love-count'
  ].join(',\n');

  var BUILTIN_SPAN_ACCENT_SURFACE_SELECTORS = [
    'body.profile-page .gear-chip', 'span.gear-chip'
  ].join(',\n');

  var BUILTIN_SPAN_TEXT_SELECTORS = [
    'body.profile-page .gear-nav-section-label', 'body.profile-page .gear-nav-item-meta',
    'body.profile-page .gear-upload-hint', 'body.profile-page .gear-detail-control-label',
    'body.profile-page .gear-nav-section-chevron', 'body.profile-page .gear-save-state',
    '.nav-link > span', '.sh-icon-link > span', 'body.org-app .sidebar-meta > span',
    'body.org-app .nav-link > span', '.mf-num', 'span.mf-dot', '.mf-time',
    '.mf-music-title', '.mf-music-artist', '.mf-music-dot', '.pv-n', '.pv-views',
    '.ig-story-name', 'span.muted', '.react-btn .n', '.react-btn .vnum',
    '.mf-media-action-label'
  ].join(',\n');

  var BUILTIN_SPAN_MUTED_SELECTORS = [
    'body.profile-page .gear-nav-item-meta', 'body.profile-page .gear-upload-hint',
    'body.profile-page .gear-save-state:not(.is-saving):not(.is-saved):not(.is-error)',
    'body.profile-page .gear-nav-section-chevron', '.stat-pill .lbl', '.stat-pill .sub',
    'span.muted', '.ig-bio .muted'
  ].join(',\n');

  var BUILTIN_GEAR_MUTED_SELECTORS = [
    'body.profile-page .gear-detail-desc', 'body.profile-page .gear-nav-item-meta',
    'body.profile-page .gear-upload-hint', 'body.profile-page .gear-detail-empty',
    'body.profile-page .gear-save-state', 'body.profile-page .gear-nav-section-chevron'
  ].join(',\n');

  var BUILTIN_GEAR_ICON_SELECTORS = [
    'body.profile-page .gear-detail-icon i', 'body.profile-page .gear-nav-section-icon i',
    'body.profile-page .gear-detail-icon [class*="ion-"]',
    'body.profile-page .gear-nav-section-icon [class*="ion-"]'
  ].join(',\n');

  function scopeSelectors(scope, selectors){
    return selectors.split(',\n').map(function(sel){
      return scope + ' ' + sel.trim();
    }).join(',\n');
  }

  function clearBuiltinThemePaintStyle(){
    var style = document.getElementById(BUILTIN_PAINT_STYLE_ID);
    if (style && style.parentNode) {
      style.parentNode.removeChild(style);
    }
  }

  function resolveBuiltinDarkChrome(prefs, appearanceMode){
    appearanceMode = normalizeAppearanceMode(appearanceMode);
    if (!!prefs.autoEnabled) {
      return shouldAutoDark();
    }
    if (appearanceMode === 'light') {
      return false;
    }
    if (appearanceMode === 'dark') {
      return true;
    }
    return false;
  }

  function ensureBuiltinThemePaintStyle(){
    var root = document.documentElement;
    if (root.getAttribute('data-msb-appearance')) {
      clearBuiltinThemePaintStyle();
      return;
    }

    var isDark = root.classList.contains('dark-auto');
    var scope = isDark
      ? 'html.dark-auto:not([data-msb-appearance])'
      : 'html:not(.dark-auto):not([data-msb-appearance])';
    var paintBg = isDark ? appearanceDarkBg() : appearanceLightBg();
    var paintText = isDark ? appearanceDarkChrome() : '#0f172a';
    var paintBorder = isDark ? 'rgba(255,255,255,.12)' : 'rgba(15,23,42,.12)';
    var style = document.getElementById(BUILTIN_PAINT_STYLE_ID);
    if (!style) {
      style = document.createElement('style');
      style.id = BUILTIN_PAINT_STYLE_ID;
      style.setAttribute('data-msb-theme-style', '1');
      document.head.appendChild(style);
    }

    style.textContent =
      scope + ',\n' +
      scopeSelectors(scope, BUILTIN_PAINT_SELECTORS) + ' {\n' +
      '  background-color: ' + paintBg + ' !important;\n' +
      '  background-image: none !important;\n' +
      '  color: ' + paintText + ' !important;\n' +
      '  border-color: ' + paintBorder + ' !important;\n' +
      '}\n' +
      scopeSelectors(scope, BUILTIN_GEAR_FG_SELECTORS) + ' {\n' +
      '  color: ' + paintText + ' !important;\n' +
      '}\n' +
      scopeSelectors(scope, BUILTIN_GEAR_MUTED_SELECTORS) + ' {\n' +
      '  color: ' + (isDark ? paintText : '#475569') + ' !important;\n' +
      '}\n' +
      scopeSelectors(scope, BUILTIN_GEAR_ICON_SELECTORS) + ' {\n' +
      '  color: ' + paintText + ' !important;\n' +
      '}\n' +
      scopeSelectors(scope, 'body.profile-page .gear-nav-item.is-active') + ' {\n' +
      '  background-color: var(--msb-palette-nav-active-bg) !important;\n' +
      '  color: var(--msb-palette-nav-active-text, var(--msb-palette-text)) !important;\n' +
      '}\n' +
      scopeSelectors(scope, 'body.profile-page .gear-nav-item.is-active .gear-nav-item-meta') + ' {\n' +
      '  color: var(--msb-palette-nav-active-text, var(--msb-palette-text-muted)) !important;\n' +
      '}\n' +
      scopeSelectors(scope, 'body.profile-page .gear-nav-section-toggle') + ' {\n' +
      '  background-color: var(--msb-palette-action-soft, var(--msb-palette-hover-bg)) !important;\n' +
      '  background-image: none !important;\n' +
      '  border: 1px solid var(--msb-palette-border) !important;\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '}\n' +
      scopeSelectors(scope, 'body.profile-page .gear-nav-item') + ' {\n' +
      '  background-color: transparent !important;\n' +
      '  background-image: none !important;\n' +
      '}\n' +
      scopeSelectors(scope, 'body.profile-page .gear-nav-section-toggle:hover, body.profile-page .gear-nav-section-toggle:focus, body.profile-page .gear-nav-item:hover, body.profile-page .gear-nav-item:focus') + ' {\n' +
      '  background-color: var(--msb-palette-hover-bg) !important;\n' +
      '  color: var(--msb-palette-text-on-hover, var(--msb-palette-text)) !important;\n' +
      '}\n' +
      scopeSelectors(scope, BUILTIN_HREF_TEXT_SELECTORS) + ' {\n' +
      '  color: var(--msb-palette-link, var(--msb-palette-text)) !important;\n' +
      '  background-color: transparent !important;\n' +
      '}\n' +
      scopeSelectors(scope, BUILTIN_HREF_TEXT_HOVER_SELECTORS) + ' {\n' +
      '  color: var(--msb-palette-link-hover, var(--msb-palette-link, var(--msb-palette-text))) !important;\n' +
      '}\n' +
      scopeSelectors(scope, BUILTIN_BTN_FILLED_SELECTORS) + ' {\n' +
      '  background-color: var(--msb-palette-btn-bg, var(--msb-palette-action, #2563eb)) !important;\n' +
      '  background-image: none !important;\n' +
      '  border-color: var(--msb-palette-btn-bg, var(--msb-palette-action, #2563eb)) !important;\n' +
      '  color: var(--msb-palette-btn-text, #ffffff) !important;\n' +
      '}\n' +
      scopeSelectors(scope, BUILTIN_BTN_FILLED_HOVER_SELECTORS) + ' {\n' +
      '  background-color: var(--msb-palette-btn-hover-bg, var(--msb-palette-btn-bg, var(--msb-palette-action, #2563eb))) !important;\n' +
      '  border-color: var(--msb-palette-btn-hover-bg, var(--msb-palette-btn-bg, var(--msb-palette-action, #2563eb))) !important;\n' +
      '  color: var(--msb-palette-btn-text, #ffffff) !important;\n' +
      '}\n' +
      scopeSelectors(scope, BUILTIN_BTN_SURFACE_SELECTORS) + ' {\n' +
      '  background-color: var(--msb-palette-surface-2, var(--msb-palette-hover-bg, var(--msb-palette-bg))) !important;\n' +
      '  background-image: none !important;\n' +
      '  border-color: var(--msb-palette-border-strong, var(--msb-palette-border)) !important;\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '}\n' +
      scopeSelectors(scope, BUILTIN_BTN_SURFACE_HOVER_SELECTORS) + ' {\n' +
      '  background-color: var(--msb-palette-hover-bg, var(--msb-palette-nav-hover)) !important;\n' +
      '  border-color: var(--msb-palette-border-strong, var(--msb-palette-border)) !important;\n' +
      '  color: var(--msb-palette-text-on-hover, var(--msb-palette-text)) !important;\n' +
      '}\n' +
      scopeSelectors(scope, BUILTIN_BTN_ICON_SELECTORS) + ' {\n' +
      '  color: inherit !important;\n' +
      '}\n' +
      scopeSelectors(scope, BUILTIN_BTN_FILLED_ICON_SELECTORS) + ' {\n' +
      '  color: var(--msb-palette-btn-text, #ffffff) !important;\n' +
      '}\n' +
      scopeSelectors(scope, BUILTIN_SPAN_ACCENT_SURFACE_SELECTORS) + ' {\n' +
      '  background-color: var(--msb-palette-action-soft, var(--msb-palette-nav-active-bg)) !important;\n' +
      '  background-image: none !important;\n' +
      '  border-color: var(--msb-palette-border-strong, var(--msb-palette-border)) !important;\n' +
      '  color: var(--msb-palette-nav-active-text, var(--msb-palette-action, var(--msb-palette-text))) !important;\n' +
      '}\n' +
      scopeSelectors(scope, BUILTIN_SPAN_SURFACE_SELECTORS) + ' {\n' +
      '  background-color: var(--msb-palette-surface-2, var(--msb-palette-hover-bg, var(--msb-palette-bg))) !important;\n' +
      '  background-image: none !important;\n' +
      '  border-color: var(--msb-palette-border-strong, var(--msb-palette-border)) !important;\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '}\n' +
      scopeSelectors(scope, '.stat-pill .num, .pv-likepill span') + ' {\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '  background-color: transparent !important;\n' +
      '}\n' +
      scopeSelectors(scope, '.stat-pill .lbl, .stat-pill .sub') + ' {\n' +
      '  color: var(--msb-palette-text-muted, var(--msb-palette-text)) !important;\n' +
      '  background-color: transparent !important;\n' +
      '}\n' +
      scopeSelectors(scope, '.stat-pill .icon, .stat-pill i, .commerce-pill i, .pv-likepill i, .feed-badge i, .badge i, span.sidebar-chip i') + ' {\n' +
      '  color: var(--msb-palette-icon, var(--msb-palette-text)) !important;\n' +
      '}\n' +
      scopeSelectors(scope, '.stat-pill .icon') + ' {\n' +
      '  background-color: var(--msb-palette-hover-bg, var(--msb-palette-surface-2)) !important;\n' +
      '}\n' +
      scopeSelectors(scope, BUILTIN_SPAN_TEXT_SELECTORS) + ' {\n' +
      '  background-color: transparent !important;\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '}\n' +
      scopeSelectors(scope, BUILTIN_SPAN_MUTED_SELECTORS) + ' {\n' +
      '  color: var(--msb-palette-text-muted, var(--msb-palette-text)) !important;\n' +
      '}\n' +
      scopeSelectors(scope, 'body.profile-page .ig-username, body.profile-page .ig-stat, body.profile-page b.ig-stat, body.profile-page .ig-bio') + ' {\n' +
      '  background-color: transparent !important;\n' +
      '  background-image: none !important;\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '}\n' +
      scopeSelectors(scope, 'body.profile-page .ig-tab:not(.active)') + ' {\n' +
      '  background-color: transparent !important;\n' +
      '  color: var(--msb-palette-text-muted, var(--msb-palette-text)) !important;\n' +
      '}\n' +
      scopeSelectors(scope, 'body.profile-page .ig-tab.active') + ' {\n' +
      '  background-color: var(--msb-palette-nav-active-bg, var(--msb-palette-surface-2, var(--msb-palette-hover-bg))) !important;\n' +
      '  color: var(--msb-palette-nav-active-text, var(--msb-palette-text)) !important;\n' +
      '  border-top-color: var(--msb-palette-nav-active-text, var(--msb-palette-text)) !important;\n' +
      '}\n' +
      scopeSelectors(scope, 'body.profile-page .ig-tab.active i') + ' {\n' +
      '  color: inherit !important;\n' +
      '}\n' +
      scopeSelectors(scope, '#ttLeftbarOverlays .tt-profile-wrap, #ttLeftbarOverlays .tt-profile-head, #ttLeftbarOverlays .tt-profile-body, .msb-profile-door-host .tt-profile-wrap, .msb-profile-door-host .tt-profile-head, .msb-profile-door-host .tt-profile-body') + ' {\n' +
      '  background-color: var(--msb-palette-bg) !important;\n' +
      '  background-image: none !important;\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '  border-color: var(--msb-palette-border, rgba(15,23,42,.12)) !important;\n' +
      '}\n' +
      scopeSelectors(scope, BUILTIN_DOOR_PROFILE_TEXT_SELECTORS) + ' {\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '  background: transparent !important;\n' +
      '  background-color: transparent !important;\n' +
      '  background-image: none !important;\n' +
      '  border-color: transparent !important;\n' +
      '  box-shadow: none !important;\n' +
      '}\n' +
      scopeSelectors(scope, BUILTIN_DOOR_PROFILE_MUTED_SELECTORS) + ' {\n' +
      '  color: var(--msb-palette-text-muted, var(--msb-palette-text)) !important;\n' +
      '}\n' +
      scopeSelectors(scope, BUILTIN_DOOR_PROFILE_ICON_SELECTORS) + ' {\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '  opacity: 1 !important;\n' +
      '}\n' +
      scopeSelectors(scope, '#ttLeftbarOverlays .tt-profile-wrap .tt-close, .msb-profile-door-host .tt-profile-wrap .tt-close') + ' {\n' +
      '  background-color: var(--msb-palette-surface-2, var(--msb-palette-hover-bg)) !important;\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '  border-color: var(--msb-palette-border-strong, var(--msb-palette-border)) !important;\n' +
      '}\n' +
      scopeSelectors(scope, '#ttLeftbarOverlays .tt-profile-wrap .tt-close:hover, #ttLeftbarOverlays .tt-profile-wrap .tt-close:focus, .msb-profile-door-host .tt-profile-wrap .tt-close:hover, .msb-profile-door-host .tt-profile-wrap .tt-close:focus') + ' {\n' +
      '  background-color: var(--msb-palette-hover-bg) !important;\n' +
      '  color: var(--msb-palette-text-on-hover, var(--msb-palette-text)) !important;\n' +
      '}\n' +
      scopeSelectors(scope, '#ttLeftbarOverlays .tt-profile-nav a:hover, #ttLeftbarOverlays .tt-profile-nav a:focus, .msb-profile-door-host .tt-profile-nav a:hover, .msb-profile-door-host .tt-profile-nav a:focus') + ' {\n' +
      '  background-color: var(--msb-palette-hover-bg) !important;\n' +
      '  color: var(--msb-palette-text-on-hover, var(--msb-palette-text)) !important;\n' +
      '}\n' +
      scopeSelectors(scope, '#ttLeftbarOverlays .tt-profile-divider, .msb-profile-door-host .tt-profile-divider') + ' {\n' +
      '  background-color: var(--msb-palette-border-strong, var(--msb-palette-border)) !important;\n' +
      '}\n' +
      scopeSelectors(scope, 'body.org-app .nav-link.active, body.org-app a.feed-tab-link.active') + ' {\n' +
      '  background-color: var(--msb-palette-nav-active-bg, var(--msb-palette-btn-bg, var(--msb-palette-action, #2563eb))) !important;\n' +
      '  color: var(--msb-palette-nav-active-text, var(--msb-palette-btn-text, #ffffff)) !important;\n' +
      '}\n' +
      scopeSelectors(scope, 'body.profile-page .gear-control') + ' {\n' +
      '  background-color: var(--msb-palette-input-bg, var(--msb-palette-bg)) !important;\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '  border-color: var(--msb-palette-border-strong, var(--msb-palette-border)) !important;\n' +
      '}\n' +
      scopeSelectors(scope, 'body.org-app label, body.org-app .card-title, body.org-app h6') + ' {\n' +
      '  color: var(--msb-palette-text) !important;\n' +
      '}\n' +
      scopeSelectors(scope, 'body.org-app .text-muted, body.org-app small') + ' {\n' +
      '  color: var(--msb-palette-text-muted, var(--msb-palette-text)) !important;\n' +
      '}\n' +
      scopeSelectors(scope, 'body.org-app .btn-primary, body.org-app a.btn-primary, body.org-app .btn-success, body.org-app .ch-btn-primary') + ' {\n' +
      '  background-color: var(--msb-palette-btn-bg, var(--msb-palette-action, #2563eb)) !important;\n' +
      '  border-color: var(--msb-palette-btn-bg, var(--msb-palette-action, #2563eb)) !important;\n' +
      '  color: var(--msb-palette-btn-text, #ffffff) !important;\n' +
      '}\n' +
      scopeSelectors(scope, 'body.org-app .icon, body.org-app i.icon, body.org-app [class*="ion-"]') + ' {\n' +
      '  color: var(--msb-palette-icon, var(--msb-palette-text)) !important;\n' +
      '}\n';
  }

  function applyThemeFromPrefs(prefs){
    prefs = prefs || readPrefs();
    var appearanceMode = resolveAppearanceMode(prefs);
    var namedPalette = isNamedPaletteMode(appearanceMode);
    var root = document.documentElement;
    var body = document.body;
    var on = false;
    var useDarkAutoClass = false;

    if (namedPalette) {
      applyPaletteVars(root, appearanceMode);
      clearBuiltinThemePaintStyle();
      on = paletteUsesDarkChrome(appearanceMode, paletteMeta(appearanceMode));
      if (prefs.autoEnabled) {
        useDarkAutoClass = shouldAutoDark();
      }
    } else {
      clearPaletteVars(root);
      on = resolveBuiltinDarkChrome(prefs, appearanceMode);
      useDarkAutoClass = !!on;
      if (on) {
        applyBuiltInDarkThemeVars(root);
      } else {
        applyBuiltInLightThemeVars(root);
      }
    }

    if (useDarkAutoClass) {
      root.classList.add('dark-auto');
      if (body) body.classList.add('dark-auto');
    } else {
      root.classList.remove('dark-auto');
      if (body) body.classList.remove('dark-auto');
    }

    if (!namedPalette) {
      ensureBuiltinThemePaintStyle();
    }

    var themeIsDark = on;
    if (root.getAttribute('data-msb-appearance')) {
      themeIsDark = paletteUsesDarkChrome(appearanceMode, paletteMeta(appearanceMode));
    }

    root.setAttribute('data-theme', themeIsDark ? 'dark' : 'light');
    root.style.colorScheme = themeIsDark ? 'dark' : 'light';
    if (body) {
      body.setAttribute('data-theme', themeIsDark ? 'dark' : 'light');
      body.style.colorScheme = themeIsDark ? 'dark' : 'light';
    }

    syncOrgCanvasAttrs(root, appearanceMode, !!prefs.autoEnabled);
    syncOrgFilledButtonTextForAuto(root, !!prefs.autoEnabled);
    syncLeftbarOverlayVars(root);

    return !!themeIsDark;
  }

  function syncLeftbarOverlayVars(root){
    var host = document.getElementById('ttLeftbarOverlays');
    if (!host || !root) return;
    var cs = getComputedStyle(root);
    var text = cs.getPropertyValue('--msb-palette-text').trim();
    var muted = cs.getPropertyValue('--msb-palette-text-muted').trim();
    var bg = cs.getPropertyValue('--msb-palette-bg').trim();
    var surface2 = cs.getPropertyValue('--msb-palette-surface-2').trim();
    var border = cs.getPropertyValue('--msb-palette-border').trim();
    var borderStrong = cs.getPropertyValue('--msb-palette-border-strong').trim();
    var hover = cs.getPropertyValue('--msb-palette-hover-bg').trim();
    if (text) {
      host.style.setProperty('--tt-text', text);
      host.style.setProperty('--tt-muted', muted || text);
    }
    if (bg) {
      host.style.setProperty('--tt-panel-bg', bg);
      host.style.setProperty('--tt-panel-bg-alt', surface2 || bg);
      host.style.setProperty('--tt-panel-bg-strong', surface2 || bg);
    }
    if (border) host.style.setProperty('--tt-panel-border', border);
    if (borderStrong) host.style.setProperty('--tt-panel-border-strong', borderStrong);
    if (hover) host.style.setProperty('--tt-control-hover', hover);
  }

  function syncOrgFilledButtonTextForAuto(root, autoEnabled){
    if (!autoEnabled || !root || root.getAttribute('data-msb-appearance')) {
      return;
    }
    var white = '#ffffff';
    root.style.setProperty('--org-btn-filled-text', white);
    root.style.setProperty('--org-btn-on-accent', white);
    if (document.body) {
      document.body.style.setProperty('--org-btn-filled-text', white);
      document.body.style.setProperty('--org-btn-on-accent', white);
    }
  }

  function syncOrgCanvasAttrs(root, appearanceMode, autoEnabled){
    appearanceMode = normalizeAppearanceMode(appearanceMode);
    if (isNamedPaletteMode(appearanceMode)) {
      root.removeAttribute('data-msb-org-light');
      if (autoEnabled) {
        root.setAttribute('data-msb-theme-auto', '1');
      } else {
        root.removeAttribute('data-msb-theme-auto');
      }
      return;
    }
    if (autoEnabled) {
      root.setAttribute('data-msb-theme-auto', '1');
      if ((appearanceMode === 'light' || appearanceMode === 'system') && !root.classList.contains('dark-auto')) {
        root.setAttribute('data-msb-org-light', '1');
      } else {
        root.removeAttribute('data-msb-org-light');
      }
      return;
    }
    root.removeAttribute('data-msb-theme-auto');
    if (appearanceMode === 'light' || appearanceMode === 'system') {
      root.setAttribute('data-msb-org-light', '1');
    } else {
      root.removeAttribute('data-msb-org-light');
    }
  }

  window.__MSBThemeCore = {
    themeUserId: themeUserId,
    scopedKey: scopedKey,
    readPrefs: readPrefs,
    writeScopedPrefs: writeScopedPrefs,
    seedPrefsFromServer: seedPrefsFromServer,
    prefsFromServerDefaults: prefsFromServerDefaults,
    applyThemeFromPrefs: applyThemeFromPrefs,
    normalizeAppearanceMode: normalizeAppearanceMode
  };

  applyThemeFromPrefs(seedPrefsFromServer());

  function refreshThemePaint(){
    var root = document.documentElement;
    syncLeftbarOverlayVars(root);
    if (root.getAttribute('data-msb-appearance')) {
      ensurePaletteStylesheet();
      ensurePalettePaintStyle();
      clearBuiltinThemePaintStyle();
      return;
    }
    ensureBuiltinThemePaintStyle();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', refreshThemePaint);
  } else {
    refreshThemePaint();
  }
  window.addEventListener('load', refreshThemePaint);
  window.addEventListener('msb-theme-change', refreshThemePaint);
  window.__MSBThemeCore.refreshPalettePaint = refreshThemePaint;
  window.__MSBThemeCore.refreshThemePaint = refreshThemePaint;
})();
