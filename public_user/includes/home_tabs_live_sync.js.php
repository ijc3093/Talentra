<?php
declare(strict_types=1);
?>
<script>
(function () {
  if (window.__msbHomeTabsLiveSync) return;
  window.__msbHomeTabsLiveSync = true;

  var endpoints = ['home_tabs_api.php', 'feed_api.php?ajax=home_tabs'];
  var lastPinsKey = '';
  var pollTimer = 0;

  function normalizePins(list) {
    var out = [];
    var seen = {};
    (Array.isArray(list) ? list : []).forEach(function (raw) {
      var slug = String(raw || '').trim().toLowerCase();
      if (slug === 'discover') slug = 'public';
      if (slug === 'commerce') slug = 'enterprise';
      if (slug === 'circle') slug = 'for-you';
      if (!slug || seen[slug]) return;
      if (slug === 'for-you' || slug === 'public' || slug === 'discover' || slug === 'feed' || slug === 'circle') return;
      seen[slug] = true;
      out.push(slug);
    });
    return out;
  }

  function pinsKey(list) {
    return normalizePins(list).join(',');
  }

  function applyPins(pins) {
    var selected = normalizePins(pins);
    lastPinsKey = pinsKey(selected);
    var urlTab = '';
    try {
      urlTab = String(new URL(window.location.href).searchParams.get('tab') || '').toLowerCase();
    } catch (eTab) {}
    if (urlTab === 'discover') urlTab = 'public';
    if (urlTab === 'commerce') urlTab = 'enterprise';
    document.querySelectorAll('a.feed-discover-tab.feed-program-tab-item').forEach(function (link) {
      var slug = String(link.getAttribute('data-program-slug') || '');
      if (!slug) {
        try { slug = String(new URL(link.href, window.location.href).searchParams.get('tab') || ''); }
        catch (eSlug) { slug = ''; }
      }
      slug = String(slug || '').toLowerCase();
      if (slug === 'discover') slug = 'public';
      if (slug === 'commerce') slug = 'enterprise';
      var on = selected.indexOf(slug) !== -1
        || (slug === urlTab && slug !== 'for-you' && slug !== 'public');
      link.hidden = !on;
      if (!on) {
        link.classList.remove('is-active');
        link.removeAttribute('aria-current');
      }
    });
    document.querySelectorAll('a.feed-program-nav-item').forEach(function (link) {
      var slug = String(link.getAttribute('data-program-slug') || '').toLowerCase();
      if (!slug) return;
      link.hidden = selected.indexOf(slug) === -1;
    });
    if (window.MSBFeedPrograms && typeof window.MSBFeedPrograms.applyPins === 'function') {
      window.MSBFeedPrograms.applyPins(selected);
    }
  }

  function parseJson(res) {
    return res.json().catch(function () { return null; });
  }

  function request(method, body) {
    function tryAt(index) {
      if (index >= endpoints.length) {
        return Promise.reject(new Error('home tabs endpoint unavailable'));
      }
      var opts = {
        method: method,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {}
      };
      if (body) {
        opts.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
        opts.body = body;
      }
      return fetch(endpoints[index], opts).then(function (res) {
        if (!res.ok) return tryAt(index + 1);
        return parseJson(res).then(function (data) {
          if (!data || data.ok !== true) return tryAt(index + 1);
          return data;
        });
      }).catch(function () {
        return tryAt(index + 1);
      });
    }
    return tryAt(0);
  }

  function pull() {
    if (window.__msbHomeTabsDirty) {
      var localPins = [];
      if (window.MSBFeedPrograms && typeof window.MSBFeedPrograms.getPins === 'function') {
        localPins = normalizePins(window.MSBFeedPrograms.getPins());
      }
      lastPinsKey = pinsKey(localPins);
      return request('POST', 'pins=' + encodeURIComponent(JSON.stringify(localPins))).then(function (data) {
        if (data && pinsKey(normalizePins(data.pins)) === lastPinsKey) {
          window.__msbHomeTabsDirty = false;
        }
        return data;
      }).catch(function () { return null; });
    }
    if (Date.now() < (window.__msbHomeTabsIgnorePullUntil || 0)) {
      return Promise.resolve(null);
    }
    return request('GET').then(function (data) {
      var pins = normalizePins(data.pins);
      var saved = data.pins_saved === true || data.pins_saved === 1 || data.pins_saved === '1';
      if (!saved) return data;
      if (pinsKey(pins) === lastPinsKey) return data;
      applyPins(pins);
      return data;
    }).catch(function () { return null; });
  }

  function push(pins) {
    var selected = normalizePins(pins);
    lastPinsKey = pinsKey(selected);
    return request('POST', 'pins=' + encodeURIComponent(JSON.stringify(selected)));
  }

  window.msbHomeTabsSync = {
    pull: pull,
    push: push,
    applyPins: applyPins
  };

  pull();
  pollTimer = window.setInterval(pull, 1500);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) pull();
  });
  window.addEventListener('focus', pull);
})();
</script>
