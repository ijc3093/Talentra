<script id="msb-resume-post-position">
(function(){
  var KEY = 'msbResumePost';
  var MAX_AGE_MS = 45 * 60 * 1000;
  var quietUntil = 0;
  var restoreTimer = null;
  var stampTimer = null;
  var circleScrollBound = false;

  function pageKind(){
    var p = String(location.pathname || '').toLowerCase();
    if (p.indexOf('/reel.php') !== -1 || /\/reel\.php$/.test(p)) return 'reel';
    if (p.indexOf('home.php') !== -1 || p.indexOf('feed.php') !== -1 || p.indexOf('public.php') !== -1 || p.indexOf('news.php') !== -1) return 'home';
    var b = document.body;
    if (b && b.classList) {
      if (b.classList.contains('reel-page')) return 'reel';
      if (b.classList.contains('home-page') || b.classList.contains('feed-page') || b.classList.contains('public-page') || b.classList.contains('news-page')) {
        if (!b.classList.contains('reel-page')) return 'home';
      }
    }
    return '';
  }

  function isHomePath(path){
    path = String(path || '').toLowerCase();
    return /\/home\.php$/.test(path) || /\/feed\.php$/.test(path) || /\/public\.php$/.test(path) || /\/news\.php$/.test(path);
  }

  function isUsableScroller(el){
    if (!el) return false;
    if (el.hidden) return false;
    try {
      var cs = window.getComputedStyle(el);
      if (cs.display === 'none' || cs.visibility === 'hidden') return false;
    } catch (e) {}
    return true;
  }

  function feedScroller(){
    var discover = document.querySelector('.feed-desktop-center > .ig-feed') || document.querySelector('.ig-feed');
    if (isUsableScroller(discover)) return discover;
    var mf = document.getElementById('mfFeed') || document.querySelector('.feed-desktop-center > .mf-feed');
    if (isUsableScroller(mf)) return mf;
    return document.scrollingElement || document.documentElement;
  }

  function save(postId){
    postId = Number(postId || 0);
    if (!postId) return;
    var kind = pageKind();
    if (!kind) return;
    var sc = feedScroller();
    var y = 0;
    try { y = Number((sc && sc.scrollTop) || window.scrollY || 0); } catch (eY) {}
    var rec = {
      kind: kind,
      path: String(location.pathname || ''),
      search: String(location.search || ''),
      postId: postId,
      y: y,
      ts: Date.now()
    };
    try { sessionStorage.setItem(KEY, JSON.stringify(rec)); } catch (e) {}
    // Keep a durable home snapshot so Clips (kind=reel) does not erase Discover/Circle return place.
    if (kind === 'home') {
      try { sessionStorage.setItem(KEY + 'Home', JSON.stringify(rec)); } catch (eHome) {}
    }
  }

  function readHomeRaw(){
    try {
      var rec = JSON.parse(sessionStorage.getItem(KEY + 'Home') || 'null');
      if (!rec || !Number(rec.postId || 0)) return null;
      if ((Date.now() - Number(rec.ts || 0)) > MAX_AGE_MS) return null;
      return rec;
    } catch (e) {
      return null;
    }
  }

  function readRaw(){
    try {
      var rec = JSON.parse(sessionStorage.getItem(KEY) || 'null');
      if (!rec || !Number(rec.postId || 0)) return null;
      if ((Date.now() - Number(rec.ts || 0)) > MAX_AGE_MS) return null;
      return rec;
    } catch (e) {
      return null;
    }
  }

  function read(){
    var rec = readRaw();
    if (!rec) return null;
    if (String(rec.kind || '') !== pageKind()) return null;
    return rec;
  }

  function clear(){
    try { sessionStorage.removeItem(KEY); } catch (e) {}
  }

  function urlFromPost(){
    try {
      var n = Number((new URL(window.location.href)).searchParams.get('from_post') || 0);
      return n > 0 ? n : 0;
    } catch (e) {
      return 0;
    }
  }

  function stampHomeUrl(postId){
    postId = Number(postId || 0);
    if (!postId) return;
    function apply(win){
      if (!win || !win.location || !win.history) return;
      try {
        var u = new URL(win.location.href);
        if (!isHomePath(u.pathname)) return;
        if (String(u.searchParams.get('from_post') || '') === String(postId)) return;
        u.searchParams.set('from_post', String(postId));
        win.history.replaceState(win.history.state || {}, win.document.title, u.pathname + u.search + u.hash);
      } catch (e) {}
    }
    apply(window);
    try { if (window.top && window.top !== window) apply(window.top); } catch (e2) {}
  }

  function findCard(postId){
    var id = String(Number(postId || 0));
    if (!id || id === '0') return null;
    return document.querySelector(
      '#mfFeed .mf-card[data-id="'+id+'"],' +
      '#mfFeed .mf-card[data-post-id="'+id+'"],' +
      '.mf-card[data-id="'+id+'"],' +
      '.mf-card[data-post-id="'+id+'"],' +
      '.public-post-card[data-post-id="'+id+'"],' +
      'article.post[data-post-id="'+id+'"],' +
      '#post-' + id
    );
  }

  function cardIsInScroller(card, sc){
    if (!card || !sc) return false;
    var cr = card.getBoundingClientRect();
    var sr = sc.getBoundingClientRect();
    var mid = (cr.top + cr.bottom) / 2;
    return mid >= (sr.top + 40) && mid <= (sr.bottom - 40);
  }

  function scrollCardIntoFeed(card){
    if (!card) return;
    var root = feedScroller();
    quietUntil = Date.now() + 1800;
    if (root && root.contains && root.contains(card)) {
      var rootRect = root.getBoundingClientRect();
      var cardRect = card.getBoundingClientRect();
      var nextTop = Number(root.scrollTop || 0) + (cardRect.top - rootRect.top);
      nextTop -= Math.max(12, (root.clientHeight - cardRect.height) / 2);
      try {
        if (typeof root.scrollTo === 'function') root.scrollTo(0, Math.max(0, nextTop));
        else root.scrollTop = Math.max(0, nextTop);
      } catch (e1) {
        try { card.scrollIntoView({ block: 'center', inline: 'nearest' }); } catch (e2) {}
      }
      return;
    }
    try { card.scrollIntoView({ block: 'center', inline: 'nearest' }); } catch (e3) {}
  }

  function pendingPostId(){
    var rec = read();
    var id = Number((rec && rec.postId) || 0) || urlFromPost();
    if (id) return id;
    var home = readHomeRaw();
    return Number((home && home.postId) || 0);
  }

  function restoreHome(){
    if (pageKind() !== 'home') return;
    // Fresh create → Circle/Discover: keep the new post pinned at the top.
    // Do not re-center a previous resume position (that clips the header).
    try{
      if (window.__MSB_SKIP_RESUME_HOME) {
        bindCircleScrollStamp();
        return;
      }
      var uFresh = new URL(window.location.href);
      if (uFresh.searchParams.get('fresh') === '1') {
        bindCircleScrollStamp();
        return;
      }
    }catch(_fresh){}
    var postId = pendingPostId();
    var home = readHomeRaw();
    var sc = feedScroller();
    if (!postId) {
      // Exact scroll place from Discover/Circle even when the card is gone.
      if (home && sc && Number(home.y || 0) >= 0) {
        try {
          var y = Math.max(0, Number(home.y || 0));
          if (typeof sc.scrollTo === 'function') sc.scrollTo(0, y);
          else sc.scrollTop = y;
        } catch (eY) {}
      }
      bindCircleScrollStamp();
      return;
    }
    var card = findCard(postId);
    if (card && sc && cardIsInScroller(card, sc)) {
      bindCircleScrollStamp();
      return;
    }
    if (restoreTimer) {
      clearTimeout(restoreTimer);
      restoreTimer = null;
    }
    var tries = 0;
    function tick(){
      tries += 1;
      postId = pendingPostId();
      card = findCard(postId);
      if (card) {
        scrollCardIntoFeed(card);
        stampHomeUrl(postId);
        if (tries < 8) {
          restoreTimer = setTimeout(tick, 220);
          return;
        }
        restoreTimer = null;
        bindCircleScrollStamp();
        return;
      }
      if (tries === 1 && home && sc && Number(home.y || 0) >= 0) {
        try {
          var y2 = Math.max(0, Number(home.y || 0));
          if (typeof sc.scrollTo === 'function') sc.scrollTo(0, y2);
          else sc.scrollTop = y2;
        } catch (eY2) {}
      }
      if (tries < 60) restoreTimer = setTimeout(tick, 120);
      else {
        restoreTimer = null;
        bindCircleScrollStamp();
      }
    }
    tick();
  }

  function visibleCardId(sc){
    sc = sc || feedScroller();
    if (!sc || !sc.querySelectorAll) return 0;
    var sr = sc.getBoundingClientRect();
    var mid = sr.top + (sr.height / 2);
    var best = 0;
    var bestDist = Infinity;
    sc.querySelectorAll('.mf-card[data-id], .mf-card[data-post-id], .public-post-card[data-post-id]').forEach(function(card){
      var r = card.getBoundingClientRect();
      if (r.bottom < sr.top || r.top > sr.bottom) return;
      var d = Math.abs(((r.top + r.bottom) / 2) - mid);
      if (d < bestDist) {
        bestDist = d;
        best = Number(card.getAttribute('data-post-id') || card.getAttribute('data-id') || 0);
      }
    });
    return best;
  }

  function bindCircleScrollStamp(){
    var feeds = [];
    var mf = document.getElementById('mfFeed');
    var ig = document.querySelector('.feed-desktop-center > .ig-feed') || document.querySelector('.ig-feed');
    if (mf) feeds.push(mf);
    if (ig && ig !== mf) feeds.push(ig);
    if (!feeds.length) return;
    feeds.forEach(function(feed){
      if (feed.getAttribute('data-msb-stamp-scroll') === '1') return;
      feed.setAttribute('data-msb-stamp-scroll', '1');
      feed.addEventListener('scroll', function(){
        if (Date.now() < quietUntil) return;
        var id = visibleCardId(feed);
        if (!id) return;
        if (stampTimer) clearTimeout(stampTimer);
        stampTimer = setTimeout(function(){
          save(id);
          stampHomeUrl(id);
        }, 120);
      }, { passive: true });
    });
    circleScrollBound = true;
  }

  function postIdFromNode(node){
    if (!node || !node.closest) return 0;
    var card = node.closest('.mf-card, .public-post-card, article.post, .reel-slide, .reel-card-row, .reel-card-main');
    if (!card) return 0;
    var slide = card.classList && card.classList.contains('reel-slide') ? card : node.closest('.reel-slide');
    if (slide) return Number(slide.getAttribute('data-post-id') || 0);
    return Number(card.getAttribute('data-post-id') || card.getAttribute('data-id') || 0);
  }

  function isCardProfileLink(a){
    if (!a || !a.closest) return false;
    if (a.closest('.sh-logopanel, .feed-ig-rail, .js-open-profile-door, .ig-feed-user-name, .tt-profile-wrap, .msb-profile-door-host')) return false;
    var href = String(a.getAttribute('href') || '');
    if (!/profile\.php(\?|#|$)/i.test(href)) return false;
    if (a.classList && (
      a.classList.contains('msb-sharing-who') ||
      a.classList.contains('mf-avatar-link') ||
      a.classList.contains('post-author-avatar-link') ||
      a.classList.contains('msb-sharing-others-item') ||
      a.classList.contains('reel-author-name') ||
      a.classList.contains('reel-avatar')
    )) {
      return true;
    }
    return !!a.closest('.mf-card, .public-post-card, article.post, .reel-slide, .mf-peer-link, .post-header, .post-author-link, .pv-name, #pvOverlay');
  }

  function goTopProfile(a){
    var url = '';
    try { url = new URL(a.href, window.location.href).href; } catch (err) {
      url = String(a.getAttribute('href') || '');
    }
    if (!url) return;
    try {
      if (window.top && window.top !== window) {
        window.top.location.assign(url);
        return;
      }
    } catch (errTop) {}
    window.location.assign(url);
  }

  function resumeReturnUrl(){
    var rec = readRaw();
    if (rec && String(rec.kind || '') === 'reel') {
      var homeRec = readHomeRaw();
      if (homeRec) rec = homeRec;
    }
    if (!rec) {
      rec = readHomeRaw();
    }
    if (!rec) return '';
    var id = Number(rec.postId || 0);
    if (!id) return '';
    if (String(rec.kind || '') === 'reel') {
      return 'reel.php?post=' + encodeURIComponent(String(id));
    }
    if (String(rec.kind || '') !== 'home') return '';
    var tab = 'for-you';
    try {
      var u = new URL(String(rec.path || 'home.php') + String(rec.search || ''), window.location.href);
      var path = String(u.pathname || '').toLowerCase();
      tab = String(u.searchParams.get('tab') || '').toLowerCase();
      if (tab === 'public') tab = 'discover';
      if (!tab) {
        if (path.indexOf('/public.php') !== -1) tab = 'discover';
        else if (path.indexOf('/news.php') !== -1) tab = 'news';
        else tab = 'for-you';
      }
    } catch (e) {}
    try {
      var out = new URL('home.php', window.location.href);
      out.searchParams.set('tab', tab);
      out.searchParams.set('from_post', String(id));
      return out.pathname + out.search;
    } catch (e2) {
      return 'home.php?tab=' + encodeURIComponent(tab) + '&from_post=' + encodeURIComponent(String(id));
    }
  }

  function goBack(fallback){
    var url = resumeReturnUrl() || String(fallback || '').trim();
    if (url) {
      window.location.assign(url);
      return true;
    }
    if (window.history.length > 1) {
      window.history.back();
      return true;
    }
    window.location.assign('home.php?tab=for-you');
    return true;
  }

  document.addEventListener('click', function(e){
    var t = e.target;
    if (!t || !t.closest) return;
    if (e.button !== 0) return;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = t.closest('a[href]');
    if (!a) return;
    var href = String(a.getAttribute('href') || '');
    if (!/profile\.php(\?|#|$)/i.test(href)) return;
    if (a.closest('.sh-logopanel, .feed-ig-rail, .js-open-profile-door, .ig-feed-user-name')) return;
    var postId = postIdFromNode(a);
    if (postId) save(postId);
    if (pageKind() === 'reel' && postId) {
      try {
        var u = new URL(location.href);
        u.searchParams.set('post', String(postId));
        history.replaceState({}, '', u.pathname + u.search + u.hash);
      } catch (err) {}
    } else if (pageKind() === 'home' && postId) {
      stampHomeUrl(postId);
    }
    if (!isCardProfileLink(a)) return;
    e.preventDefault();
    e.stopPropagation();
    if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
    goTopProfile(a);
  }, true);

  document.addEventListener('click', function(e){
    var btn = e.target && e.target.closest ? e.target.closest('#msbProfileBack') : null;
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    goBack(btn.getAttribute('href') || 'home.php?tab=for-you');
  }, true);

  window.MSBResumePost = {
    save: save,
    read: read,
    readRaw: readRaw,
    clear: clear,
    restoreHome: restoreHome,
    findCard: findCard,
    stampHomeUrl: stampHomeUrl,
    resumeReturnUrl: resumeReturnUrl,
    goBack: goBack
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', restoreHome);
  } else {
    restoreHome();
  }
  window.addEventListener('pageshow', function(){ restoreHome(); });
  document.addEventListener('msb:public-tab-content-ready', function(){ restoreHome(); });
})();
</script>
