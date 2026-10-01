/**
 * Sales Management hub: left nav ↔ right panel hash switching.
 * Lives outside .sh-mainpanel (leftbar) so SPA main swaps cannot drop the binder.
 */
(function () {
  'use strict';

  if (window.__salesHubNavBooted) {
    // Re-entered after SPA/main swap: ensure panels match current hash.
    if (typeof window.__salesShowView === 'function') {
      try { window.__salesShowView(window.location.hash || 'dashboard'); } catch (e) {}
    }
    return;
  }
  window.__salesHubNavBooted = true;

  var DEFAULT_VIEW = 'dashboard';
  var FLEX_VIEWS = {
    dashboard: 1,
    detail_employee: 1,
    'support-center': 1,
    orders: 1,
    transactions: 1,
    payments: 1,
    'product-catalog': 1,
    products: 1,
    inventory: 1
  };
  var DOC_SCROLL_VIEWS = {
    overview: 1,
    payroll: 1,
    accounts: 1,
    timecard: 1
  };

  function knownSlugs() {
    var raw = '';
    try {
      raw = String(document.documentElement.getAttribute('data-sales-views') || '');
    } catch (e) {}
    if (!raw) return null;
    var map = {};
    raw.split(',').forEach(function (s) {
      s = String(s || '').trim();
      if (s) map[s] = 1;
    });
    return map;
  }

  function aliasSlug(slug) {
    slug = String(slug || '').replace(/^#/, '').trim();
    try { slug = decodeURIComponent(slug); } catch (e) {}
    if (slug === 'order-cancel-table') return 'notification';
    if (slug === 'product-table') return 'inventory';
    if (slug === 'Products' || slug === 'products-list') return 'product-catalog';
    if (slug === 'messages') return 'message';
    if (slug === 'payouts') return 'payments';
    if (slug === 'returns-refunds') return 'refunds';
    return slug;
  }

  function normalize(hash) {
    var slug = aliasSlug(hash);
    if (!slug) return DEFAULT_VIEW;
    var views = document.querySelectorAll('.sales-management-view[data-sales-view], [data-sales-view]');
    for (var i = 0; i < views.length; i++) {
      if (String(views[i].getAttribute('data-sales-view') || '').trim() === slug) {
        return slug;
      }
    }
    // Trust the server-published slug list when the panel node is temporarily missing.
    var known = knownSlugs();
    if (known && known[slug]) return slug;
    return DEFAULT_VIEW;
  }

  function clearDocScrollLocks() {
    try {
      ['height', 'max-height', 'overflow', 'overflow-x', 'overflow-y'].forEach(function (prop) {
        document.documentElement.style.removeProperty(prop);
        document.body.style.removeProperty(prop);
      });
      var main = document.querySelector('.sh-mainpanel');
      var page = document.querySelector('.sh-pagebody');
      if (main) {
        ['height', 'max-height', 'overflow'].forEach(function (prop) {
          main.style.removeProperty(prop);
        });
      }
      if (page) {
        ['height', 'max-height', 'overflow', 'display'].forEach(function (prop) {
          page.style.removeProperty(prop);
        });
      }
    } catch (e) {}
  }

  function applyDocScroll(slug) {
    if (!DOC_SCROLL_VIEWS[slug]) return;
    try {
      document.documentElement.style.setProperty('height', 'auto', 'important');
      document.documentElement.style.setProperty('max-height', 'none', 'important');
      document.documentElement.style.setProperty('overflow-x', 'hidden', 'important');
      document.documentElement.style.setProperty('overflow-y', 'auto', 'important');
      document.body.style.setProperty('height', 'auto', 'important');
      document.body.style.setProperty('max-height', 'none', 'important');
      document.body.style.setProperty('overflow-x', 'hidden', 'important');
      document.body.style.setProperty('overflow-y', 'auto', 'important');
      var main = document.querySelector('.sh-mainpanel');
      var page = document.querySelector('.sh-pagebody');
      if (main) {
        main.style.setProperty('height', 'auto', 'important');
        main.style.setProperty('max-height', 'none', 'important');
        main.style.setProperty('overflow', 'visible', 'important');
      }
      if (page) {
        page.style.setProperty('height', 'auto', 'important');
        page.style.setProperty('max-height', 'none', 'important');
        page.style.setProperty('overflow', 'visible', 'important');
      }
    } catch (e) {}
  }

  function showSalesView(hash) {
    var slug = normalize(hash);
    clearDocScrollLocks();

    var views = document.querySelectorAll('.sales-management-view[data-sales-view]');
    if (!views.length) {
      views = document.querySelectorAll('[data-sales-view]');
    }
    for (var i = 0; i < views.length; i++) {
      var view = views[i];
      var key = String(view.getAttribute('data-sales-view') || '').trim();
      var on = key === slug;
      view.classList.toggle('is-active', on);
      if (on) {
        view.removeAttribute('hidden');
        view.style.setProperty('display', FLEX_VIEWS[key] ? 'flex' : 'block', 'important');
        view.style.setProperty('visibility', 'visible', 'important');
        view.style.setProperty('opacity', '1', 'important');
        if (!FLEX_VIEWS[key]) {
          view.style.setProperty('height', 'auto', 'important');
          view.style.setProperty('max-height', 'none', 'important');
          view.style.setProperty('overflow', 'visible', 'important');
        } else {
          view.style.removeProperty('height');
          view.style.removeProperty('max-height');
          view.style.removeProperty('overflow');
        }
      } else {
        view.setAttribute('hidden', 'hidden');
        view.style.setProperty('display', 'none', 'important');
        view.style.removeProperty('visibility');
        view.style.removeProperty('opacity');
        view.style.removeProperty('height');
        view.style.removeProperty('max-height');
        view.style.removeProperty('overflow');
      }
    }

    document.documentElement.removeAttribute('data-sales-initial-view');
    document.documentElement.setAttribute('data-sales-active-view', slug);
    applyDocScroll(slug);

    var activeLink = null;
    document.querySelectorAll('[data-sales-nav]').forEach(function (link) {
      var linkSlug = String(link.getAttribute('data-sales-nav') || '').trim();
      var on = linkSlug === slug || (slug === 'inventory-detail' && linkSlug === 'inventory');
      link.classList.toggle('active', on);
      if (on && link.classList.contains('sales-management-nav-link') && !activeLink) {
        activeLink = link;
      }
    });
    if (activeLink && typeof activeLink.scrollIntoView === 'function') {
      try {
        activeLink.scrollIntoView({ block: 'nearest', inline: 'nearest' });
      } catch (eScroll) {
        try { activeLink.scrollIntoView(false); } catch (e2) {}
      }
    }

    try {
      window.scrollTo(0, 0);
    } catch (e) {}

    if (typeof window.__salesSyncHeader === 'function') {
      try { window.__salesSyncHeader(slug); } catch (e2) {}
    }
    try {
      window.dispatchEvent(new CustomEvent('sales-view-change', { detail: { slug: slug } }));
    } catch (e3) {}

    return slug;
  }

  function setHash(slug, push) {
    slug = normalize(slug);
    // Always paint first — do not depend on hashchange (pushState / preventDefault quirks).
    showSalesView(slug);

    var cur = aliasSlug(window.location.hash);
    if (cur === slug) return slug;

    var next = window.location.pathname + window.location.search + '#' + slug;
    try {
      if (push === false && window.history && window.history.replaceState) {
        window.history.replaceState({ salesView: slug, orgNav: true }, '', next);
      } else if (window.history && window.history.pushState) {
        window.history.pushState({ salesView: slug, orgNav: true }, '', next);
      } else {
        window.location.hash = slug;
      }
    } catch (e) {
      try { window.location.hash = slug; } catch (e2) {}
    }
    return slug;
  }

  function isSalesNavClick(link) {
    if (!link || link.tagName !== 'A') return false;
    if (!link.hasAttribute('data-sales-nav')) return false;
    var href = String(link.getAttribute('href') || '');
    if (/[?&](edit|inv_product)=/i.test(href)) return false;
    if (href.charAt(0) === '#') return true;
    if (/sales_management\.php/i.test(href)) return true;
    return !!link.classList.contains('sales-management-nav-link')
      || !!link.classList.contains('org-sales-support-center');
  }

  document.addEventListener('click', function (event) {
    var link = event.target && event.target.closest
      ? event.target.closest('a[data-sales-nav]')
      : null;
    if (!isSalesNavClick(link)) return;
    if (event.button !== 0) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    var slug = String(link.getAttribute('data-sales-nav') || '').trim();
    if (!slug) return;

    event.preventDefault();
    if (typeof event.stopImmediatePropagation === 'function') {
      event.stopImmediatePropagation();
    } else {
      event.stopPropagation();
    }
    setHash(slug, true);
    try { if (typeof link.blur === 'function') link.blur(); } catch (e) {}
  }, true);

  window.addEventListener('hashchange', function () {
    showSalesView(window.location.hash);
  });

  window.addEventListener('popstate', function () {
    showSalesView(window.location.hash);
  });

  window.__salesShowView = showSalesView;
  window.__salesSetHash = setHash;

  function boot() {
    showSalesView(window.location.hash || DEFAULT_VIEW);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
