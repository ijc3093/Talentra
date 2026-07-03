(function(){
  'use strict';

  var core = window.__MSBThemeCore || null;

  function themeUserId(){
    if (core && typeof core.themeUserId === 'function') return core.themeUserId();
    var uid = parseInt(String(window.__MSB_THEME_USER_ID || '0'), 10);
    return uid > 0 ? uid : 0;
  }

  function normalizeManualMode(mode){
    return (mode === 'light' || mode === 'dark') ? mode : 'dark';
  }

  function normalizeAppearanceMode(mode){
    if (core && typeof core.normalizeAppearanceMode === 'function') {
      return core.normalizeAppearanceMode(mode);
    }
    mode = String(mode || '').toLowerCase().trim();
    if (mode === 'light' || mode === 'dark' || mode === 'system') return mode;
    if (window.__MSB_APPEARANCE_PALETTES && window.__MSB_APPEARANCE_PALETTES[mode]) return mode;
    return 'system';
  }

  function readPrefs(){
    if (core && typeof core.readPrefs === 'function') {
      return core.readPrefs();
    }

    var defaults = window.__MSB_THEME_DEFAULTS || {};
    return {
      autoEnabled: !!defaults.autoEnabled,
      manualMode: normalizeManualMode(defaults.manualMode),
      appearanceMode: normalizeAppearanceMode(defaults.appearanceMode || window.__MSB_THEME_DB_MODE || 'system')
    };
  }

  function writePrefs(prefs){
    prefs = prefs || {};
    var next = {
      autoEnabled: !!prefs.autoEnabled,
      manualMode: normalizeManualMode(prefs.manualMode),
      appearanceMode: normalizeAppearanceMode(prefs.appearanceMode || prefs.manualMode || 'dark')
    };
    var uid = themeUserId();

    if (uid > 0) {
      window.__MSBThemePrefsUserId = uid;
      window.__MSBThemePrefs = next;
      if (core && typeof core.writeScopedPrefs === 'function') {
        core.writeScopedPrefs(next);
      }
      return next;
    }

    if (core && typeof core.writeScopedPrefs === 'function') {
      return core.writeScopedPrefs(next);
    }

    return next;
  }

  function apply(){
    var prefs = readPrefs();
    var on = false;

    if (core && typeof core.applyThemeFromPrefs === 'function') {
      on = !!core.applyThemeFromPrefs(prefs);
    } else {
      on = prefs.autoEnabled ? false : (prefs.manualMode === 'dark');
    }

    try {
      window.dispatchEvent(new CustomEvent('msb-theme-change', {
        detail: {
          dark: !!on,
          autoEnabled: !!prefs.autoEnabled,
          manualMode: prefs.manualMode,
          appearanceMode: prefs.appearanceMode
        }
      }));
    } catch (e) {}
  }

  window.MSBTheme = window.MSBTheme || {};
  window.MSBTheme.getPrefs = function(){ return readPrefs(); };
  window.MSBTheme.setPrefs = function(next){
    var current = readPrefs();
    if (typeof next.autoEnabled === 'boolean') current.autoEnabled = next.autoEnabled;
    if (typeof next.manualMode === 'string') current.manualMode = normalizeManualMode(next.manualMode);
    if (typeof next.appearanceMode === 'string') current.appearanceMode = normalizeAppearanceMode(next.appearanceMode);
    writePrefs(current);
    apply();
    return current;
  };
  window.MSBTheme.apply = apply;
  window.MSBTheme.setAutoEnabled = function(flag){
    return window.MSBTheme.setPrefs({ autoEnabled: !!flag });
  };
  window.MSBTheme.setManualMode = function(mode){
    return window.MSBTheme.setPrefs({ manualMode: mode, appearanceMode: mode });
  };
  window.MSBTheme.setAppearanceMode = function(mode){
    return window.MSBTheme.setPrefs({
      autoEnabled: false,
      appearanceMode: mode,
      manualMode: (mode === 'light' || mode === 'dark') ? mode : currentManualFor(mode)
    });
  };

  function currentManualFor(mode){
    mode = normalizeAppearanceMode(mode);
    if (mode === 'light') return 'light';
    if (mode === 'dark') return 'dark';
    var meta = window.__MSB_APPEARANCE_PALETTES && window.__MSB_APPEARANCE_PALETTES[mode];
    return (meta && meta.dark) ? 'dark' : 'light';
  }

  apply();
  document.addEventListener('DOMContentLoaded', apply);

  try {
    if (window.matchMedia) {
      var mq = window.matchMedia('(prefers-color-scheme: dark)');
      if (mq && mq.addEventListener) {
        mq.addEventListener('change', function(){ if (readPrefs().autoEnabled) apply(); });
      } else if (mq && mq.addListener) {
        mq.addListener(function(){ if (readPrefs().autoEnabled) apply(); });
      }
    }
  } catch (e) {}
})();
