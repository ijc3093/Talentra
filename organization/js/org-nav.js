(function () {
  'use strict';

  var MEDIA_PAGES = {
    'feed.php': true,
    'messages.php': true,
    'dashboard.php': true
  };

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
    'theme-bootstrap.js',
    'dark-auto.js',
    'org-nav.js',
    'admin-nav.js'
  ];

  var FEED_DOM_SELECTORS = [
    '#readMoreModal',
    '#repliesModal',
    '#editPostModal',
    '#mainMedia',
    '.read-more-modal',
    '.feed-post-media',
    '.feed-post',
    '.feed-layout',
    '.feed-sidebar',
    '.feed-main',
    '.feed-card',
    '.media-strip',
    '.modal-backdrop'
  ];

  var FEED_STYLE_RE = /\.feed-post-media|\.feed-layout|\.feed-sidebar|\.feed-main|\.feed-card|\.feed-post\b|\.feed-tabs|\.media-strip|#mainMedia|\.post-media\b/;

  // Shared layout class names used outside feed.php — keep these style blocks when importing.
  var ORG_PAGE_LAYOUT_RE = /\.(dashboard-card|rows-scroll|post-card|posts-tabs|members-card|bulkbar|sh-mainpanel|sh-pagebody|card-body-fixed|dash-toprow|feed-toolbar|searchbar|pager|member-row)\b/;

  var THEME_STYLE_IDS = {
    'org-layout-critical': true,
    'org-no-feed-media': true,
    'org-page-bg-critical': true,
    'org-tail-light-critical': true,
    'org-tail-dark-critical': true,
    'msb-door-shell-critical': true,
    'msb-builtin-theme-paint': true,
    'msb-palette-paint': true,
    'pub-page-light-critical': true
  };

  function isPreservedThemeStyle(style) {
    if (!style) return false;
    if (style.id && THEME_STYLE_IDS[style.id]) return true;
    if (style.getAttribute('data-msb-theme-style') === '1') return true;
    return false;
  }

  function shouldSkipImportedStyle(css, allowFeedStyles) {
    if (allowFeedStyles) return false;
    if (!FEED_STYLE_RE.test(css)) return false;
    if (ORG_PAGE_LAYOUT_RE.test(css)) return false;
    // Keep messages/compose Dark auto theme blocks that mention feed-badge etc.
    if (/\.org-page-messages\b|\.org-page-compose\b|\.org-page-commerce\b|\.org-page-sales_management\b|\.chat-shell\b|\.composer-fixed\b|\.members-list-wrap\b|\.commerce-panel\b|\.commerce-page\b|\.sales-management-metric\b/.test(css)) {
      return false;
    }
    return true;
  }

  var NO_FEED_CSS =
    'video,audio,.feed-post-media,.feed-post,.feed-layout,.feed-sidebar,.feed-main,'
    + '.feed-card,.media-strip,#mainMedia,#readMoreModal,#repliesModal,#editPostModal,'
    + '.read-more-modal,.modal-backdrop{display:none!important;visibility:hidden!important;'
    + 'width:0!important;height:0!important;max-width:0!important;max-height:0!important;'
    + 'opacity:0!important;pointer-events:none!important;position:fixed!important;'
    + 'left:-10000px!important;top:-10000px!important;z-index:-1!important;overflow:hidden!important;}';

  var cache = new Map();
  var navToken = 0;
  var activeController = null;
  var fadeMs = 100;

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

  function pageFromUrl(url) {
    try {
      return new URL(url, window.location.href).pathname.split('/').pop() || '';
    } catch (e) {
      return '';
    }
  }

  function pageSlug(page) {
    return page.replace(/\.php$/i, '').replace(/[^a-z0-9_-]+/gi, '');
  }

  function isFeedPage(url) {
    return pageFromUrl(url) === 'feed.php';
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

  function isOrgNavLink(link) {
    if (!link || link.tagName !== 'A') return false;
    if (link.getAttribute('data-org-nav') !== '1') return false;
    if (link.hasAttribute('download')) return false;
    if (link.target && link.target !== '_self') return false;

    var href = link.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#') return false;
    if (/^(mailto:|tel:|javascript:)/i.test(href)) return false;

    try {
      var url = new URL(href, window.location.href);
      if (url.origin !== window.location.origin) return false;
      if (!/\/organization\//i.test(url.pathname)) return false;
      if (!/\.php$/i.test(url.pathname)) return false;
      if (/logout\.php$/i.test(url.pathname)) return false;
    } catch (e2) {
      return false;
    }

    return true;
  }

  function pickMainPanel(root) {
    if (!root) return null;
    var panels = root.querySelectorAll('.sh-mainpanel');
    if (!panels.length) return null;
    if (panels.length === 1) return panels[0];

    var best = panels[0];
    for (var i = 1; i < panels.length; i++) {
      if ((panels[i].textContent || '').length > (best.textContent || '').length) {
        best = panels[i];
      }
    }
    return best;
  }

  function getMainPanel() {
    return pickMainPanel(document);
  }

  var MEDIA_KILL_SELECTORS =
    'video,audio,.feed-post-media,.feed-post,.feed-layout,.feed-sidebar,.feed-main,'
    + '.feed-card,.media-strip,#mainMedia,#readMoreModal,#repliesModal,#editPostModal,'
    + '.read-more-modal,.modal-backdrop,.post-media';

  function forceHideNode(el) {
    if (!el || !el.style) return;
    el.style.setProperty('display', 'none', 'important');
    el.style.setProperty('visibility', 'hidden', 'important');
    el.style.setProperty('opacity', '0', 'important');
    el.style.setProperty('pointer-events', 'none', 'important');
    el.style.setProperty('width', '0', 'important');
    el.style.setProperty('height', '0', 'important');
    el.style.setProperty('max-width', '0', 'important');
    el.style.setProperty('max-height', '0', 'important');
    el.style.setProperty('overflow', 'hidden', 'important');
    el.style.setProperty('position', 'fixed', 'important');
    el.style.setProperty('left', '-10000px', 'important');
    el.style.setProperty('top', '-10000px', 'important');
    el.style.setProperty('z-index', '-1', 'important');
  }

  function ensureNavShield() {
    var shield = document.getElementById('org-nav-shield');
    if (shield) return shield;
    shield = document.createElement('div');
    shield.id = 'org-nav-shield';
    shield.setAttribute('aria-hidden', 'true');
    document.body.appendChild(shield);
    return shield;
  }

  function showNavShieldNow() {
    ensureNavShield();
    document.body.classList.add('is-navigating');
  }

  function syncHtmlFeedFlag(url) {
    var onFeed = isFeedPage(url);
    document.documentElement.setAttribute('data-org-feed', onFeed ? '1' : '0');
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
      if (!isOrgNavLink(link)) return;
      link.classList.toggle('active', sameUrl(link.href, url));
    });
  }

  function syncBodyPageClass(url) {
    var slug = pageSlug(pageFromUrl(url));
    document.body.classList.add('org-app');
    Array.prototype.slice.call(document.body.classList).forEach(function (cls) {
      if (cls.indexOf('org-page-') === 0) {
        document.body.classList.remove(cls);
      }
    });
    if (slug) {
      document.body.classList.add('org-page-' + slug);
    }
  }

  function syncMediaGuard(url) {
    var guard = document.getElementById('org-no-feed-media');
    if (isFeedPage(url)) {
      if (guard && guard.parentNode) guard.parentNode.removeChild(guard);
      return;
    }
    if (!guard) {
      guard = document.createElement('style');
      guard.id = 'org-no-feed-media';
      document.head.appendChild(guard);
    }
    guard.textContent = NO_FEED_CSS;
  }

  function shouldKeepMediaNode(el, allowMsgAttach) {
    if (!el) return true;
    if (el.closest('.sh-headpanel')) return true;
    if (allowMsgAttach && el.closest('.msg-attach')) return true;
    return false;
  }

  function killMediaNode(media, allowMsgAttach) {
    if (shouldKeepMediaNode(media, allowMsgAttach)) return;
    forceHideNode(media);
    try { media.pause(); } catch (e) {}
    try {
      media.removeAttribute('src');
      media.querySelectorAll('source').forEach(function (s) { s.removeAttribute('src'); });
      if (media.load) media.load();
    } catch (e2) {}
    if (media.parentNode) media.parentNode.removeChild(media);
  }

  function destroyAllFeedMedia(targetUrl) {
    var targetPage = pageFromUrl(targetUrl || window.location.href);
    var keepFeedMedia = targetPage === 'feed.php';
    var allowMsgAttach = targetPage === 'messages.php';

    if (keepFeedMedia) return;

    document.querySelectorAll(MEDIA_KILL_SELECTORS).forEach(function (el) {
      if (shouldKeepMediaNode(el, allowMsgAttach)) return;
      if (el.tagName === 'VIDEO' || el.tagName === 'AUDIO') {
        killMediaNode(el, allowMsgAttach);
        return;
      }
      forceHideNode(el);
      if (el.parentNode) el.parentNode.removeChild(el);
    });

    var main = getMainPanel();
    if (main) {
      main.querySelectorAll(MEDIA_KILL_SELECTORS).forEach(function (el) {
        if (shouldKeepMediaNode(el, allowMsgAttach)) return;
        if (el.tagName === 'VIDEO' || el.tagName === 'AUDIO') {
          killMediaNode(el, allowMsgAttach);
          return;
        }
        forceHideNode(el);
        if (el.parentNode) el.parentNode.removeChild(el);
      });
    }
  }

  function dispatchNavComplete(url) {
    if (isFeedPage(url)) {
      syncHtmlFeedFlag(url);
      syncBodyPageClass(url);
      syncMediaGuard(url);
    }
    try {
      document.dispatchEvent(new CustomEvent('org-nav-complete', { detail: { url: url } }));
    } catch (e) {}
  }

  function purgeBodyOrphans() {
    var main = getMainPanel();
    if (!main) return;

    // Feed keeps modals + page scripts as siblings after the main panel.
    if (isFeedPage(window.location.href)) return;

    var node = main.nextElementSibling;
    while (node) {
      var next = node.nextElementSibling;
      if (node.id === 'org-nav-shield' || node.id === 'org-page-scripts') {
        node = next;
        continue;
      }
      if (node.tagName === 'SCRIPT' && isGlobalScript(node)) {
        break;
      }
      node.parentNode.removeChild(node);
      node = next;
    }
  }

  function scrubFeedStylesFromHead(url) {
    if (isFeedPage(url)) return;
    document.querySelectorAll('style').forEach(function (style) {
      if (style.id === 'org-layout-critical' || style.id === 'org-no-feed-media') return;
      var css = style.textContent || '';
      if (FEED_STYLE_RE.test(css) && style.parentNode) {
        style.parentNode.removeChild(style);
      }
    });
  }

  function teardownForTarget(targetUrl) {
    syncHtmlFeedFlag(targetUrl);
    syncBodyPageClass(targetUrl);
    syncMediaGuard(targetUrl);
    destroyAllFeedMedia(targetUrl);
    purgeBodyOrphans();
    scrubFeedStylesFromHead(targetUrl);

    document.querySelectorAll('.modal.show').forEach(function (modal) {
      modal.classList.remove('show');
      modal.style.display = 'none';
      modal.setAttribute('aria-hidden', 'true');
    });

    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
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
    document.querySelectorAll('style[data-org-page-style="1"]').forEach(function (el) {
      if (isPreservedThemeStyle(el)) {
        el.removeAttribute('data-org-page-style');
        return;
      }
      el.parentNode.removeChild(el);
    });
    var scriptHost = document.getElementById('org-page-scripts');
    if (scriptHost) scriptHost.innerHTML = '';
  }

  function syncPageStyles(doc, url) {
    clearPageAssets();
    var allowFeedStyles = isFeedPage(url);

    doc.head.querySelectorAll('style').forEach(function (style) {
      if (isPreservedThemeStyle(style)) return;
      var css = style.textContent || '';
      if (!css.trim()) return;
      if (shouldSkipImportedStyle(css, allowFeedStyles)) return;

      var clone = document.createElement('style');
      clone.setAttribute('data-org-page-style', '1');
      clone.textContent = css;
      document.head.appendChild(clone);
    });

    // Ensure page theme stylesheets survive SPA navigations.
    var page = pageFromUrl(url);
    var msgTheme = document.getElementById('org-messages-theme-css');
    if (page === 'messages.php') {
      if (!msgTheme) {
        msgTheme = document.createElement('link');
        msgTheme.id = 'org-messages-theme-css';
        msgTheme.rel = 'stylesheet';
        msgTheme.href = 'css/org-messages-theme.css?v=3';
        document.head.appendChild(msgTheme);
      }
    } else if (msgTheme && msgTheme.parentNode) {
      msgTheme.parentNode.removeChild(msgTheme);
    }

    var commercePages = {
      'commerce.php': true,
      'sales_management.php': true,
      'orders.php': true,
      'products.php': true,
      'shop_settings.php': true,
      'seller_journey.php': true,
      'quotations.php': true,
      'invoices.php': true,
      'payments.php': true,
      'delivery.php': true,
      'returns_refunds.php': true,
      'discounts_promotions.php': true,
      'commerce_brand_select.php': true,
      'recent_orders.php': true,
      'commerce_analytics.php': true
    };
    var commerceTheme = document.getElementById('org-commerce-theme-css');
    if (commercePages[page] || page.indexOf('sales_') === 0) {
      if (!commerceTheme) {
        commerceTheme = document.createElement('link');
        commerceTheme.id = 'org-commerce-theme-css';
        commerceTheme.rel = 'stylesheet';
        commerceTheme.href = 'css/org-commerce-theme.css?v=2';
        document.head.appendChild(commerceTheme);
      }
    } else if (commerceTheme && commerceTheme.parentNode) {
      commerceTheme.parentNode.removeChild(commerceTheme);
    }
  }

  function syncBodyState(doc, url) {
    if (!doc.body) return;
    var theme = doc.body.getAttribute('data-theme');
    if (theme) document.body.setAttribute('data-theme', theme);
    if (doc.documentElement) {
      document.documentElement.className = doc.documentElement.className;
    }
    syncBodyPageClass(url || window.location.href);
  }

  function ensureScriptHost() {
    var host = document.getElementById('org-page-scripts');
    if (host) return host;
    host = document.createElement('div');
    host.id = 'org-page-scripts';
    host.hidden = true;
    document.body.appendChild(host);
    return host;
  }

  function getPageFragments(doc, url) {
    var main = pickMainPanel(doc);
    if (!main) return null;

    var extras = [];
    if (isFeedPage(url)) {
      var node = main.nextElementSibling;
      while (node) {
        if (node.id === 'org-page-scripts') break;
        if (node.tagName === 'SCRIPT' && isGlobalScript(node)) break;
        extras.push(node);
        node = node.nextElementSibling;
      }
    }

    return { main: main, extras: extras };
  }

  function removeCurrentPageFragments() {
    var main = getMainPanel();
    if (!main) return;

    var toRemove = [];
    var node = main.nextElementSibling;
    while (node) {
      if (node.id === 'org-page-scripts') break;
      if (node.tagName === 'SCRIPT' && isGlobalScript(node)) break;
      toRemove.push(node);
      node = node.nextElementSibling;
    }
    toRemove.forEach(function (el) {
      el.parentNode.removeChild(el);
    });
  }

  function insertPageFragments(bundle) {
    var main = getMainPanel();
    if (!main || !bundle || !bundle.main) return null;

    main.outerHTML = bundle.main.outerHTML;
    var anchor = getMainPanel();
    if (!anchor) return null;

    bundle.extras.forEach(function (extra) {
      anchor.insertAdjacentHTML('afterend', extra.outerHTML);
      anchor = anchor.nextElementSibling || anchor;
    });

    return getMainPanel();
  }

  function runTrailingScripts(bundle) {
    var host = ensureScriptHost();
    host.innerHTML = '';
    if (!bundle || !bundle.main) return;

    var nodes = [bundle.main].concat(bundle.extras);
    nodes.forEach(function (container) {
      if (!container.querySelectorAll) return;
      container.querySelectorAll('script').forEach(function (script) {
        if (isGlobalScript(script)) return;
        host.appendChild(activateScript(script));
      });
    });

    activateScripts(getMainPanel());
  }

  async function fetchHtml(url, signal) {
    if (cache.has(url)) return cache.get(url);

    var response = await fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'text/html', 'X-Org-Nav': '1' },
      signal: signal
    });

    if (!response.ok) throw new Error('Request failed: ' + response.status);

    var html = await response.text();
    cache.set(url, html);
    return html;
  }

  function prefetch(url) {
    if (!url || cache.has(url)) return;
    fetchHtml(url).catch(function () {});
  }

  function shouldHardLeaveFeed(fromUrl, toUrl) {
    return isFeedPage(fromUrl) && !isFeedPage(toUrl);
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
      teardownForTarget(url);
      return;
    }

    if (shouldHardLeaveFeed(window.location.href, url)) {
      window.location.href = url;
      return;
    }

    var token = ++navToken;
    if (activeController) activeController.abort();
    activeController = new AbortController();

    showNavShieldNow();
    destroyAllFeedMedia(url);
    teardownForTarget(url);

    try {
      var html = await fetchHtml(url, activeController.signal);
      if (token !== navToken) return;

      var doc = new DOMParser().parseFromString(html, 'text/html');
      var bundle = getPageFragments(doc, url);
      if (!bundle || !bundle.main) throw new Error('Missing .sh-mainpanel');

      syncPageStyles(doc, url);
      syncBodyState(doc, url);
      teardownForTarget(url);

      await sleep(fadeMs);
      if (token !== navToken) return;

      removeCurrentPageFragments();
      var newMain = insertPageFragments(bundle);
      if (!newMain) throw new Error('Main panel missing after swap');

      runTrailingScripts(bundle);

      if (doc.title) document.title = doc.title;
      if (push) history.pushState({ orgNav: true, url: url }, '', url);

      if (isFeedPage(url)) {
        syncHtmlFeedFlag(url);
        syncBodyPageClass(url);
        syncMediaGuard(url);
      }

      setActiveNav(url);
      bindLinks(document);
      window.scrollTo(0, 0);

      dispatchNavComplete(url);

      newMain.classList.add('is-nav-ready');
      await sleep(fadeMs);
      newMain.classList.remove('is-nav-ready');
    } catch (err) {
      if (err && err.name === 'AbortError') return;
      window.location.href = url;
    } finally {
      document.body.classList.remove('is-navigating');
    }
  }

  function onLinkActivate(link) {
    if (!isOrgNavLink(link)) return;

    var target = link.href;
    showNavShieldNow();
    destroyAllFeedMedia(target);

    if (shouldHardLeaveFeed(window.location.href, target)) {
      teardownForTarget(target);
      return;
    }

    teardownForTarget(target);
    setPendingNav(link);
  }

  function onLinkClick(event) {
    var link = event.currentTarget;
    if (!isOrgNavLink(link)) return;
    if (event.defaultPrevented) return;
    if (event.button !== 0) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    event.preventDefault();
    onLinkActivate(link);

    if (shouldHardLeaveFeed(window.location.href, link.href)) {
      window.location.href = link.href;
      return;
    }

    navigateTo(link.href, { push: true });
  }

  function bindLinks(root) {
    root.querySelectorAll('a[data-org-nav="1"]').forEach(function (link) {
      if (link.__orgNavBound) return;
      link.__orgNavBound = true;
      link.addEventListener('pointerdown', function (event) {
        if (event.button !== 0) return;
        onLinkActivate(link);
      });
      link.addEventListener('click', onLinkClick);
      link.addEventListener('mouseenter', function () {
        prefetch(link.href);
      }, { passive: true });
    });
  }

  function boot() {
    ensureNavShield();
    destroyAllFeedMedia(window.location.href);
    teardownForTarget(window.location.href);
    syncBodyPageClass(window.location.href);

    document.head.querySelectorAll('style').forEach(function (style) {
      if (isPreservedThemeStyle(style)) return;
      style.setAttribute('data-org-page-style', '1');
    });

    if (pageFromUrl(window.location.href) === 'messages.php' && !document.getElementById('org-messages-theme-css')) {
      var msgThemeBoot = document.createElement('link');
      msgThemeBoot.id = 'org-messages-theme-css';
      msgThemeBoot.rel = 'stylesheet';
      msgThemeBoot.href = 'css/org-messages-theme.css?v=3';
      document.head.appendChild(msgThemeBoot);
    }
    var bootPage = pageFromUrl(window.location.href);
    var commercePagesBoot = {
      'commerce.php': true,
      'sales_management.php': true,
      'orders.php': true,
      'products.php': true,
      'shop_settings.php': true,
      'seller_journey.php': true,
      'quotations.php': true,
      'invoices.php': true,
      'payments.php': true,
      'delivery.php': true,
      'returns_refunds.php': true,
      'discounts_promotions.php': true,
      'commerce_brand_select.php': true,
      'recent_orders.php': true,
      'commerce_analytics.php': true
    };
    if ((commercePagesBoot[bootPage] || bootPage.indexOf('sales_') === 0) && !document.getElementById('org-commerce-theme-css')) {
      var commerceThemeBoot = document.createElement('link');
      commerceThemeBoot.id = 'org-commerce-theme-css';
      commerceThemeBoot.rel = 'stylesheet';
      commerceThemeBoot.href = 'css/org-commerce-theme.css?v=2';
      document.head.appendChild(commerceThemeBoot);
    }

    bindLinks(document);

    if (!history.state || !history.state.orgNav) {
      history.replaceState({ orgNav: true, url: window.location.href }, '', window.location.href);
    }
  }

  ready(function () {
    boot();

    window.addEventListener('popstate', function (event) {
      if (event.state && event.state.orgNav) {
        navigateTo(window.location.href, { push: false });
        return;
      }
      window.location.reload();
    });

    window.addEventListener('pageshow', function () {
      destroyAllFeedMedia(window.location.href);
      teardownForTarget(window.location.href);
    });
  });
})();
