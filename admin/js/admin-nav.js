(function () {
  'use strict';

  var GLOBAL_SCRIPT_MARKERS = [
    'jquery.js',
    'jquery-ui.js',
    'popper.js',
    'bootstrap.js',
    'perfect-scrollbar',
    'moment.js',
    'jquery.flot',
    'flot-spline',
    'datatables',
    'select2',
    'summernote',
    'shamcey.js',
    'admin-nav.js'
  ];

  var cache = new Map();
  var navToken = 0;
  var activeController = null;
  var fadeMs = 180;

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
      return;
    }
    fn();
  }

  function sleep(ms) {
    return new Promise(function (resolve) {
      window.setTimeout(resolve, ms);
    });
  }

  function sameUrl(a, b) {
    try {
      var left = new URL(a, window.location.href);
      var right = new URL(b, window.location.href);
      return left.pathname === right.pathname && left.search === right.search;
    } catch (e) {
      return false;
    }
  }

  function isAdminNavLink(link) {
    if (!link || link.tagName !== 'A') return false;
    if (link.getAttribute('data-admin-nav') !== '1') return false;
    if (link.hasAttribute('download')) return false;
    if (link.target && link.target !== '_self') return false;

    var href = link.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#') return false;
    if (/^(mailto:|tel:|javascript:)/i.test(href)) return false;

    try {
      var url = new URL(href, window.location.href);
      if (url.origin !== window.location.origin) return false;
      if (!/\.php$/i.test(url.pathname)) return false;
      if (/logout\.php$/i.test(url.pathname)) return false;
    } catch (e2) {
      return false;
    }

    return true;
  }

  function getMainPanel() {
    return document.querySelector('.sh-mainpanel');
  }

  function setPendingNav(link) {
    document.querySelectorAll('.sh-sideleft-menu a.nav-link.is-nav-pending').forEach(function (el) {
      el.classList.remove('is-nav-pending');
    });
    if (link) link.classList.add('is-nav-pending');
  }

  function setActiveNav(url) {
    document.querySelectorAll('.sh-sideleft-menu a.nav-link').forEach(function (link) {
      link.classList.remove('is-nav-pending');
      if (!isAdminNavLink(link)) return;
      link.classList.toggle('active', sameUrl(link.href, url));
    });
  }

  function isGlobalScript(script) {
    var src = script.getAttribute('src') || '';
    if (!src) return false;
    return GLOBAL_SCRIPT_MARKERS.some(function (marker) {
      return src.indexOf(marker) !== -1;
    });
  }

  function activateScript(script) {
    var el = document.createElement('script');
    Array.prototype.forEach.call(script.attributes, function (attr) {
      el.setAttribute(attr.name, attr.value);
    });
    el.textContent = script.textContent;
    return el;
  }

  function activateScripts(root) {
    if (!root) return;
    root.querySelectorAll('script').forEach(function (script) {
      if (isGlobalScript(script)) {
        script.parentNode.removeChild(script);
        return;
      }
      script.parentNode.replaceChild(activateScript(script), script);
    });
  }

  function clearPageAssets() {
    document.querySelectorAll('style[data-admin-page-style="1"]').forEach(function (el) {
      el.parentNode.removeChild(el);
    });
    var scriptHost = document.getElementById('admin-page-scripts');
    if (scriptHost) scriptHost.innerHTML = '';
  }

  function syncPageStyles(doc) {
    clearPageAssets();

    doc.head.querySelectorAll('style').forEach(function (style) {
      if (style.id === 'admin-layout-critical') return;

      var css = style.textContent || '';
      if (!css.trim()) return;
      if (css.indexOf('.note-modal-backdrop') !== -1 && css.indexOf('.users-card') === -1 && css.indexOf('.accounts-card') === -1) {
        return;
      }

      var clone = document.createElement('style');
      clone.setAttribute('data-admin-page-style', '1');
      clone.textContent = css;
      document.head.appendChild(clone);
    });
  }

  function ensureScriptHost() {
    var host = document.getElementById('admin-page-scripts');
    if (host) return host;

    host = document.createElement('div');
    host.id = 'admin-page-scripts';
    host.hidden = true;
    document.body.appendChild(host);
    return host;
  }

  function runTrailingScripts(doc, sourceMain) {
    var host = ensureScriptHost();
    host.innerHTML = '';

    var node = sourceMain.nextElementSibling;
    while (node) {
      if (node.tagName === 'SCRIPT' && !isGlobalScript(node)) {
        host.appendChild(activateScript(node));
      }
      node = node.nextElementSibling;
    }

    activateScripts(getMainPanel());
  }

  async function fetchHtml(url, signal) {
    if (cache.has(url)) {
      return cache.get(url);
    }

    var response = await fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        Accept: 'text/html',
        'X-Admin-Nav': '1'
      },
      signal: signal
    });

    if (!response.ok) {
      throw new Error('Request failed: ' + response.status);
    }

    var html = await response.text();
    cache.set(url, html);
    return html;
  }

  function prefetch(url) {
    if (!url || cache.has(url)) return;
    fetchHtml(url).catch(function () {});
  }

  async function navigateTo(url, options) {
    options = options || {};
    var push = options.push !== false;
    var main = getMainPanel();

    if (!main) {
      window.location.href = url;
      return;
    }

    if (sameUrl(url, window.location.href)) {
      setActiveNav(url);
      return;
    }

    var token = ++navToken;
    if (activeController) activeController.abort();
    activeController = new AbortController();

    main.classList.add('is-nav-busy');

    try {
      var html = await fetchHtml(url, activeController.signal);
      if (token !== navToken) return;

      var doc = new DOMParser().parseFromString(html, 'text/html');
      var nextMain = doc.querySelector('.sh-mainpanel');
      if (!nextMain) throw new Error('Missing .sh-mainpanel');

      syncPageStyles(doc);

      main.classList.add('is-nav-enter');
      await sleep(fadeMs);
      if (token !== navToken) return;

      main.outerHTML = nextMain.outerHTML;

      var newMain = getMainPanel();
      if (!newMain) throw new Error('Main panel missing after swap');

      runTrailingScripts(doc, nextMain);

      if (doc.title) document.title = doc.title;
      if (push) history.pushState({ adminNav: true, url: url }, '', url);

      setActiveNav(url);
      window.scrollTo(0, 0);

      newMain.classList.add('is-nav-enter', 'is-nav-busy');
      await sleep(16);
      newMain.classList.remove('is-nav-busy');
      newMain.classList.add('is-nav-ready');
      await sleep(fadeMs);
      newMain.classList.remove('is-nav-enter', 'is-nav-ready');
    } catch (err) {
      if (err && err.name === 'AbortError') return;
      window.location.href = url;
    }
  }

  function onLinkClick(event) {
    var link = event.currentTarget;
    if (!isAdminNavLink(link)) return;
    if (event.defaultPrevented) return;
    if (event.button !== 0) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    event.preventDefault();
    setPendingNav(link);
    navigateTo(link.href, { push: true });
  }

  function bindLinks(root) {
    root.querySelectorAll('.sh-sideleft-menu a[data-admin-nav="1"]').forEach(function (link) {
      if (link.__adminNavBound) return;
      link.__adminNavBound = true;
      link.addEventListener('click', onLinkClick);
      link.addEventListener('mouseenter', function () {
        prefetch(link.href);
      }, { passive: true });
      link.addEventListener('focus', function () {
        prefetch(link.href);
      }, { passive: true });
    });
  }

  ready(function () {
    document.body.classList.add('admin-app');

    document.head.querySelectorAll('style').forEach(function (style) {
      if (style.id === 'admin-layout-critical') return;
      style.setAttribute('data-admin-page-style', '1');
    });

    bindLinks(document);

    if (!history.state || !history.state.adminNav) {
      history.replaceState({ adminNav: true, url: window.location.href }, '', window.location.href);
    }

    window.addEventListener('popstate', function (event) {
      if (event.state && event.state.adminNav) {
        navigateTo(window.location.href, { push: false });
        return;
      }
      window.location.reload();
    });
  });
})();
