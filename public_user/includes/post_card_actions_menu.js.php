(function(window){
  'use strict';

  var opts = window.MSBPostCardMenuOpts || {};
  var $ = window.jQuery || null;

  function escHtml(s){
    return String(s || '').replace(/[&<>"']/g, function(m){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
    });
  }

  function normalizeVisibility(vis){
    vis = String(vis || '').trim().toLowerCase();
    if(vis === 'private') return 'private';
    if(vis === 'friends' || vis === 'friend') return 'friends';
    return 'public';
  }

  function visibilityMeta(vis){
    var key = normalizeVisibility(vis);
    if(key === 'private'){
      return { key:'private', icon:'fa-lock', label:'Private', title:'Private — Only you can see this' };
    }
    if(key === 'friends'){
      return { key:'friends', icon:'fa-users', label:'Friends', title:'Friends — Only friends can see this' };
    }
    return { key:'public', icon:'fa-globe', label:'Public', title:'Public — Anyone can see this' };
  }

  /** Compact icon after post time (globe / friends / lock). */
  function visibilityBadgeHtml(vis, extraClass){
    var meta = visibilityMeta(vis);
    var cls = ('post-vis-badge mf-vis post-vis-' + meta.key + ' ' + String(extraClass || '')).trim();
    return '<span class="'+escHtml(cls)+'" data-visibility="'+escHtml(meta.key)+'" title="'+escHtml(meta.title)+'" aria-label="'+escHtml(meta.title)+'">'
      + '<i class="fa '+escHtml(meta.icon)+'" aria-hidden="true"></i>'
      + '<span class="post-vis-sr">'+escHtml(meta.label)+'</span>'
      + '</span>';
  }

  function personLabel(person){
    person = person || {};
    var name = String(person.display_name || person.name || '').trim();
    if (name) return name;
    var un = String(person.username || '').trim();
    return un || 'User';
  }

  function personProfileHref(person){
    person = person || {};
    var un = String(person.username || '').trim();
    if (un) return 'profile.php?username=' + encodeURIComponent(un);
    var id = parseInt(person.id || 0, 10) || 0;
    if (id > 0) return 'profile.php?id=' + String(id);
    return 'profile.php';
  }

  /**
   * Headline: "John • now [badge] is sharing with Akin."
   * 3+: "… with Akin and others" — Others opens a name dropdown.
   */
  function authorSharingWithHtml(author, taggedPeople, opts){
    opts = opts || {};
    author = author || {};
    var authorName = String(author.display_name || author.name || author.username || 'User').trim() || 'User';
    var authorHref = String(author.href || author.profile_href || '').trim();
    if (!authorHref) authorHref = personProfileHref(author);
    var linkClass = String(opts.linkClass || 'msb-sharing-who');
    var mutedClass = String(opts.mutedClass || 'msb-sharing-with');
    var linkAuthor = opts.linkAuthor !== false;
    var afterAuthorHtml = String(opts.afterAuthorHtml || '');
    var authorHtml = escHtml(authorName);
    if (linkAuthor && authorHref && authorHref !== '#') {
      authorHtml = '<a class="'+escHtml(linkClass)+'" href="'+escHtml(authorHref)+'">'+authorHtml+'</a>';
    }
    var people = Array.isArray(taggedPeople) ? taggedPeople.filter(function(p){
      return p && (personLabel(p) !== '');
    }) : [];
    if (!people.length) return authorHtml + afterAuthorHtml;
    function whoHtml(p){
      var label = escHtml(personLabel(p));
      var href = personProfileHref(p);
      if (!href || href === '#') return label;
      return '<a class="'+escHtml(linkClass)+'" href="'+escHtml(href)+'">'+label+'</a>';
    }
    function othersDropdownHtml(rest){
      var items = rest.map(function(p){
        var label = escHtml(personLabel(p));
        var href = personProfileHref(p);
        var un = escHtml(String(p.username || '').trim());
        return '<a class="msb-sharing-others-item" role="option" href="'+escHtml(href)+'">'
          + '<span class="msb-sharing-others-name">'+label+'</span>'
          + (un ? '<span class="msb-sharing-others-user">@'+un+'</span>' : '')
          + '</a>';
      }).join('');
      return '<span class="msb-sharing-others-wrap">'
        + '<button type="button" class="msb-sharing-others-btn" aria-expanded="false" aria-haspopup="listbox">Others</button>'
        + '<span class="msb-sharing-others-menu" role="listbox" hidden>'+items+'</span>'
        + '</span>';
    }
    var muted = '<span class="'+escHtml(mutedClass)+'"> is sharing with </span>';
    var head = authorHtml + afterAuthorHtml + muted;
    if (people.length === 1) {
      return head + whoHtml(people[0]) + '.';
    }
    if (people.length === 2) {
      return head + whoHtml(people[0])
        + '<span class="'+escHtml(mutedClass)+'"> and </span>'
        + whoHtml(people[1]) + '.';
    }
    return head + whoHtml(people[0])
      + '<span class="'+escHtml(mutedClass)+'"> and </span>'
      + othersDropdownHtml(people.slice(1))
      + '.';
  }

  function closeSharingOthersMenus(exceptWrap){
    document.querySelectorAll('.msb-sharing-others-wrap.is-open').forEach(function(wrap){
      if (exceptWrap && wrap === exceptWrap) return;
      wrap.classList.remove('is-open');
      var btn = wrap.querySelector('.msb-sharing-others-btn');
      var menu = wrap.querySelector('.msb-sharing-others-menu');
      if (btn) btn.setAttribute('aria-expanded', 'false');
      if (menu) menu.hidden = true;
    });
  }

  function toggleSharingOthersMenu(btn){
    var wrap = closest(btn, '.msb-sharing-others-wrap');
    if (!wrap) return;
    var menu = wrap.querySelector('.msb-sharing-others-menu');
    if (!menu) return;
    var open = !wrap.classList.contains('is-open');
    closeSharingOthersMenus(open ? wrap : null);
    wrap.classList.toggle('is-open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    menu.hidden = !open;
  }

  /**
   * True when text is only @username tokens (e.g. "@akin_t @dayo_a").
   */
  function textIsPeopleTagOnly(text){
    text = String(text || '').trim();
    if (!text) return false;
    if (!/(?:^|[\s,])@[A-Za-z0-9_]{2,50}\b/.test(' ' + text)) return false;
    var rest = text.replace(/@[A-Za-z0-9_]{2,50}\b/g, '').replace(/[\s,.;:!?\-]+/g, '');
    return rest === '';
  }

  /**
   * Hide mention-only captions when people tags already power "is sharing with".
   */
  function displayTextWithoutTagHandles(text, taggedPeople){
    text = String(text || '').trim();
    if (!text) return '';
    if (!Array.isArray(taggedPeople) || !taggedPeople.length) return text;
    if (textIsPeopleTagOnly(text)) return '';
    return text;
  }

  function closest(el, sel){
    return el && el.closest ? el.closest(sel) : null;
  }

  var PORTAL_CLASS = 'pcm-menu-portal';
  var activePortal = null;
  var activePortalWrap = null;

  function isOnMediaWrap(wrap){
    return !!(wrap && wrap.closest && wrap.closest('.mf-head--on-media, .standard-media-topbar'));
  }

  function shouldUsePortalMenu(wrap){
    if(opts.always_portal) return true;
    return isOnMediaWrap(wrap);
  }

  function menuActionEls(menu){
    if(!menu) return [];
    return Array.prototype.slice.call(menu.querySelectorAll('.pcm-item'));
  }

  function removePortal(){
    if(activePortal && activePortal.parentNode){
      try { activePortal.parentNode.removeChild(activePortal); } catch(e){}
    }
    activePortal = null;
    activePortalWrap = null;
  }

  function positionPortal(btn, portal, wrap){
    if(!btn || !portal) return;
    var prevDisplay = portal.style.display;
    var prevVis = portal.style.visibility;
    portal.style.display = 'block';
    portal.style.visibility = 'hidden';

    var rect = btn.getBoundingClientRect();
    var mw = portal.offsetWidth || 220;
    var mh = portal.offsetHeight || 80;
    var gap = 8;
    var top = rect.bottom + gap;
    var left = rect.right - mw;
    var vw = Math.max(document.documentElement.clientWidth, window.innerWidth || 0);
    var vh = Math.max(document.documentElement.clientHeight, window.innerHeight || 0);
    var menuSurface = String((wrap && wrap.getAttribute('data-menu-surface')) || opts.menu_surface || '').toLowerCase();
    var isPublicPage = !!(document.body && document.body.classList.contains('public-page'));

    // Profile post menus live outside the card so they never cover its media.
    if(menuSurface === 'profile' && !isPublicPage){
      var profileCard = btn.closest('.mf-card, .post, article');
      if(profileCard){
        var profileCardRect = profileCard.getBoundingClientRect();
        var profileMedia = profileCard.querySelector('.media-stage, .mf-media');
        var profileAnchorRect = profileMedia ? profileMedia.getBoundingClientRect() : profileCardRect;
        top = Math.max(10, rect.top);
        left = profileAnchorRect.right + gap;
        // Small screens have no right rail. Keep the menu visible and place it
        // below the trigger instead of laying it over the media.
        if(left + mw > vw - 10){
          left = Math.max(10, Math.min(rect.right - mw, vw - mw - 10));
          top = rect.bottom + gap;
        }
      }
    // Feed and Public menus open outside their center column, including posts
    // whose header/fries button is above (rather than over) the media.
    } else if(menuSurface === 'feed' || menuSurface === 'public' || isPublicPage){
      var centerCard = btn.closest('.mf-card, .post, article');
      if(centerCard){
        var centerCardRect = centerCard.getBoundingClientRect();
        var centerColumn = centerCard.closest('.feed-desktop-center');
        var centerAnchorRect = centerColumn ? centerColumn.getBoundingClientRect() : centerCardRect;
        top = Math.max(10, rect.top);
        left = centerAnchorRect.right + gap;
        if(left + mw > vw - 10){
          left = centerAnchorRect.left - mw - gap;
        }
        // On narrow viewports neither side has enough room. Keep the menu in
        // the viewport and below the button instead of covering the media.
        if(left < 10 || left + mw > vw - 10){
          left = Math.max(10, Math.min(rect.right - mw, vw - mw - 10));
          top = rect.bottom + gap;
        }
      }
    }

    if(left < 10) left = 10;
    if(left + mw > vw - 10) left = Math.max(10, vw - mw - 10);
    if(top + mh > vh - 10){
      top = Math.max(10, rect.top - gap - mh);
    }

    portal.style.top = top + 'px';
    portal.style.left = left + 'px';
    portal.style.right = 'auto';
    portal.style.visibility = prevVis || '';
    portal.style.display = prevDisplay || 'block';
  }

  function openPortalMenu(wrap, btn, menu){
    removePortal();
    document.querySelectorAll('.post-card-menu.open, .mf-menu.post-card-menu.open').forEach(function(m){
      m.classList.remove('open');
      m.style.display = '';
    });
    document.querySelectorAll('.post-card-menu-wrap.pcm-wrap-open, .mf-menu-wrap.pcm-wrap-open').forEach(function(w){
      w.classList.remove('pcm-wrap-open');
      var b = w.querySelector('.post-card-menu-btn');
      if(b) b.setAttribute('aria-expanded', 'false');
    });

    wrap.classList.add('pcm-wrap-open');
    btn.setAttribute('aria-expanded', 'true');
    // Keep the in-card menu closed; the body portal is the only visible dropdown.
    menu.classList.remove('open');
    menu.style.display = 'none';

    var clone = menu.cloneNode(true);
    clone.classList.add(PORTAL_CLASS, 'open');
    clone.style.position = 'fixed';
    clone.style.zIndex = '100000';
    clone.style.minWidth = '220px';
    clone.style.display = 'block';
    // Story-door fries: mark the portaled menu so Archive lands in Archive → Stories.
    var fromStoryDoor = !!(wrap && (
      wrap.id === 'ttStoriesMenuWrap' ||
      (wrap.classList && wrap.classList.contains('tt-stories-menu-wrap')) ||
      String(wrap.getAttribute('data-menu-surface') || '').indexOf('story') !== -1
    ));
    if(fromStoryDoor){
      clone.setAttribute('data-story-hide', '1');
      clone.querySelectorAll('.pcm-archive').forEach(function(btn){
        btn.setAttribute('data-story-hide', '1');
      });
    }

    document.body.appendChild(clone);
    positionPortal(btn, clone, wrap);
    activePortal = clone;
    activePortalWrap = wrap;
  }

  function closeMenus(except){
    var wasStoryMenu = !!window.__pcmStoryMenuOpen;
    removePortal();
    document.querySelectorAll('.post-card-menu-wrap.pcm-wrap-open, .mf-menu-wrap.pcm-wrap-open').forEach(function(wrap){
      wrap.classList.remove('pcm-wrap-open');
      var btn = wrap.querySelector('.post-card-menu-btn');
      if(btn) btn.setAttribute('aria-expanded', 'false');
    });
    document.querySelectorAll('.post-card-menu.open, .mf-menu.post-card-menu.open').forEach(function(menu){
      if(except && menu === except) return;
      menu.classList.remove('open');
      menu.style.display = '';
      menu.style.position = '';
      menu.style.top = '';
      menu.style.left = '';
      menu.style.right = '';
      menu.style.zIndex = '';
      menu.style.minWidth = '';
    });
    if(wasStoryMenu){
      window.__pcmStoryMenuOpen = false;
      resumeStoryDoorAfterMenu();
    }
  }

  function linkItem(cls, href, icon, label, extraAttrs){
    return '<a class="pcm-item ' + cls + '" href="' + escHtml(href) + '" role="menuitem"' + (extraAttrs || '') + '>' +
      '<i class="' + escHtml(icon) + '" aria-hidden="true"></i><span>' + escHtml(label) + '</span></a>';
  }

  function buttonItem(cls, icon, label, extraAttrs){
    return '<button type="button" class="pcm-item ' + cls + '" role="menuitem"' + (extraAttrs || '') + '>' +
      '<i class="' + escHtml(icon) + '" aria-hidden="true"></i><span>' + escHtml(label) + '</span></button>';
  }

  function menuDivider(){
    return '<div class="pcm-divider" role="separator"></div>';
  }

  function trackApiUrl(){
    return String(opts.api_url || 'feed_api.php');
  }

  function absoluteShareUrl(postId){
    postId = Number(postId || 0);
    if(!postId) return '';
    try{
      var here = new URL(window.location.href);
      var dir = here.pathname.replace(/[^/]+$/, '');
      return here.origin + dir + 'share_post.php?id=' + encodeURIComponent(String(postId));
    }catch(e){
      return (window.location.origin || '') + '/share_post.php?id=' + encodeURIComponent(String(postId));
    }
  }

  // Canonical copy-link target: one post page (no feed scrolling to find the card).
  function absolutePostUrl(postId, wrap){
    postId = Number(postId || 0);
    if(!postId) return '';
    try{
      var here = new URL(window.location.href);
      var dir = here.pathname.replace(/[^/]+$/, '');
      return here.origin + dir + 'post.php?id=' + encodeURIComponent(String(postId));
    }catch(e){
      return (window.location.origin || '') + '/post.php?id=' + encodeURIComponent(String(postId));
    }
  }

  function shareTitleForPost(postId){
    postId = Number(postId || 0);
    var card = document.querySelector(
      '.mf-card[data-id="'+String(postId)+'"], .public-post-card[data-post-id="'+String(postId)+'"], [data-post-id="'+String(postId)+'"]'
    );
    var title = '';
    if(card){
      title = String(card.getAttribute('data-title') || card.getAttribute('data-full-desc') || '').trim();
    }
    if(!title) title = 'Check out this post on Talentra';
    if(title.length > 120) title = title.slice(0, 117) + '…';
    return title;
  }

  function shareMediaSrcForPost(postId){
    postId = Number(postId || 0);
    var card = document.querySelector(
      '.mf-card[data-id="'+String(postId)+'"], .public-post-card[data-post-id="'+String(postId)+'"]'
    );
    if(!card) return '';
    var media = card.querySelector('img.mf-media, img.public-media, .mf-media img, .media-stage img, video[src], video source');
    if(!media) return '';
    var src = String(media.currentSrc || media.src || media.getAttribute('src') || '').trim();
    if(!src) return '';
    try{ return new URL(src, window.location.href).toString(); }catch(e){ return src; }
  }

  function recordShareOnce(postId){
    postId = Number(postId || 0);
    if(!postId) return;
    var url = trackApiUrl();
    var payload = { ajax: 'share', post_id: postId, share_action: 'add' };
    if($){
      $.post(url, payload, function(res){
        if(res && res.ok !== false) syncPostTrackState(postId, res);
      }, 'json');
      return;
    }
    var body = new URLSearchParams(payload);
    fetch(url, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(res){
        if(res && res.ok !== false) syncPostTrackState(postId, res);
      })
      .catch(function(){});
  }

  function closePcmShareSheet(){
    window.__pcmSharePostId = 0;
    window.__pcmShareUrl = '';
    window.__pcmShareTitle = '';
    hideModal('pcmShareSheet');
  }

  var pcmTagSelected = {};
  var pcmMentionSelected = {};
  var pcmTagger = null;

  function syncSelfTagUi(postId, meTagged){
    postId = Number(postId || 0);
    meTagged = !!meTagged;
    if(!postId) return;
    document.querySelectorAll(
      '.mf-card[data-id="'+String(postId)+'"], .public-post-card[data-post-id="'+String(postId)+'"], [data-post-id="'+String(postId)+'"]'
    ).forEach(function(card){
      card.setAttribute('data-me-tagged', meTagged ? '1' : '0');
    });
    document.querySelectorAll('.pcm-tag-self[data-post-id="'+String(postId)+'"]').forEach(function(btn){
      btn.setAttribute('data-me-tagged', meTagged ? '1' : '0');
      btn.classList.toggle('is-active', meTagged);
      var label = btn.querySelector('span');
      if(label) label.textContent = meTagged ? 'Remove from Tags' : 'Add to Tags';
    });
    // Portal clone may not keep data-post-id on the button — match open menu too.
    document.querySelectorAll('.pcm-menu-portal .pcm-tag-self').forEach(function(btn){
      btn.setAttribute('data-me-tagged', meTagged ? '1' : '0');
      btn.classList.toggle('is-active', meTagged);
      var label = btn.querySelector('span');
      if(label) label.textContent = meTagged ? 'Remove from Tags' : 'Add to Tags';
    });
  }

  function toggleSelfTagOnPost(postId, btn){
    postId = Number(postId || 0);
    if(!postId) return;
    var currently = btn ? (String(btn.getAttribute('data-me-tagged') || '0') === '1') : false;
    var body = new URLSearchParams({
      action: 'self_toggle',
      post_id: String(postId)
    });
    fetch('ajax/post_tags_save.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body,
      credentials: 'same-origin'
    }).then(function(r){ return r.json(); }).then(function(res){
      if(!res || !res.ok){
        pcmToast((res && res.error) ? String(res.error) : 'Could not update Tags.');
        return;
      }
      var meTagged = Number(res.me_tagged || 0) === 1;
      syncSelfTagUi(postId, meTagged);
      pcmToast(meTagged ? 'Saved to your Tags.' : 'Removed from your Tags.');
    }).catch(function(){
      pcmToast('Could not update Tags.');
    });
  }

  function closePcmTagSheet(opts){
    opts = opts || {};
    var fromStory = !!window.__pcmTagFromStory;
    window.__pcmTagPostId = 0;
    pcmTagSelected = {};
    hideModal('pcmTagSheet');
    var input = document.getElementById('pcmTagPeopleInput');
    if(input) input.value = '';
    var chips = document.getElementById('pcmTagPeopleChips');
    if(chips) chips.innerHTML = '';
    if(fromStory){
      window.__pcmTagFromStory = false;
      if(opts.afterSend){
        playStoryThroughThenClose();
      } else {
        resumeStoryDoorAfterMenu();
      }
    }
  }

  function renderPcmTagChips(){
    var wrap = document.getElementById('pcmTagPeopleChips');
    if(!wrap) return;
    wrap.innerHTML = '';
    Object.keys(pcmTagSelected).forEach(function(id){
      var u = pcmTagSelected[id];
      if(!u) return;
      var chip = document.createElement('span');
      chip.className = 'msb-tag-chip';
      chip.innerHTML = '@' + String(u.username || id).replace(/</g,'&lt;') + ' <button type="button" aria-label="Remove">&times;</button>';
      chip.querySelector('button').addEventListener('click', function(){
        delete pcmTagSelected[id];
        renderPcmTagChips();
      });
      wrap.appendChild(chip);
    });
  }

  function addPcmTagUser(u){
    if(!u || !u.id) return;
    pcmTagSelected[String(u.id)] = {
      id: Number(u.id),
      username: String(u.username || ''),
      name: String(u.name || ''),
      image: String(u.image || '')
    };
    renderPcmTagChips();
    var input = document.getElementById('pcmTagPeopleInput');
    if(input) input.value = '';
  }

  function ensurePcmTagger(){
    var input = document.getElementById('pcmTagPeopleInput');
    if(!input) return;
    if(window.MSBMentionAC && typeof window.MSBMentionAC.bind === 'function'){
      window.MSBMentionAC.bind(input, addPcmTagUser);
      input.setAttribute('data-msb-mention', '1');
    }
  }

  function openPcmTagSheet(postId){
    postId = Number(postId || 0);
    if(postId <= 0) return false;
    var dialog = document.getElementById('pcmTagSheet');
    if(!dialog){
      pcmToast('Tag sheet unavailable.');
      return false;
    }
    window.__pcmTagPostId = postId;
    pcmTagSelected = {};
    renderPcmTagChips();
    var hid = document.getElementById('pcmTagPostId');
    if(hid) hid.value = String(postId);
    var input = document.getElementById('pcmTagPeopleInput');
    if(input) input.value = '';
    ensurePcmTagger();
    showModal('pcmTagSheet');
    // Re-bind after dialog is open in case autocomplete initialized earlier without chip handler.
    ensurePcmTagger();
    fetch('ajax/post_tags_save.php?action=list&post_id=' + encodeURIComponent(String(postId)), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function(r){ return r.json(); }).then(function(data){
      if(!data || !data.ok) return;
      if(Number(window.__pcmTagPostId || 0) !== postId) return;
      (data.users || []).forEach(function(u){ addPcmTagUser(u); });
      if(input){
        try { input.focus(); } catch(e) {}
      }
    }).catch(function(){});
    return true;
  }

  function savePcmTags(){
    var postId = Number(window.__pcmTagPostId || 0);
    if(postId <= 0){
      closePcmTagSheet();
      return;
    }
    var ids = Object.keys(pcmTagSelected).map(function(k){ return Number(k); }).filter(function(n){ return n > 0; });
    var input = document.getElementById('pcmTagPeopleInput');
    var mentionText = input ? String(input.value || '').trim() : '';
    var body = new URLSearchParams();
    body.set('action', 'save');
    body.set('post_id', String(postId));
    body.set('tagged_user_ids', ids.join(','));
    // Fallback: resolve any leftover @username typed in the field (e.g. if chip callback missed).
    if (mentionText) body.set('mention_text', mentionText);
    var btn = document.getElementById('pcmTagSaveBtn');
    if(btn){ btn.disabled = true; btn.style.opacity = '.7'; }
    fetch('ajax/post_tags_save.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: body
    }).then(function(r){ return r.json(); }).then(function(data){
      if(btn){ btn.disabled = false; btn.style.opacity = ''; }
      if(!data || !data.ok){
        pcmToast((data && data.error) ? ('Could not save tags (' + data.error + ').') : 'Could not save tags.');
        return;
      }
      var savedCount = Array.isArray(data.users) ? data.users.length : ids.length;
      closePcmTagSheet({ afterSend: true });
      pcmToast(savedCount ? ('Tagged ' + savedCount + (savedCount === 1 ? ' person.' : ' people.')) : 'Tags cleared.');
      try{
        if(window.MSBNotificationsUI && typeof window.MSBNotificationsUI.refresh === 'function'){
          window.MSBNotificationsUI.refresh();
        }
      }catch(_e){}
    }).catch(function(){
      if(btn){ btn.disabled = false; btn.style.opacity = ''; }
      pcmToast('Network error while saving tags.');
    });
  }

  function isStoryDoorOpen(){
    try{
      var wrap = document.getElementById('tt-stories-wrap');
      return !!(wrap && wrap.classList && wrap.classList.contains('is-open'));
    }catch(e){
      return false;
    }
  }

  function isStoryMenuWrap(wrap){
    if(!wrap) return false;
    return wrap.id === 'ttStoriesMenuWrap' ||
      !!(wrap.classList && wrap.classList.contains('tt-stories-menu-wrap')) ||
      String(wrap.getAttribute('data-menu-surface') || '').indexOf('story') !== -1;
  }

  function pauseStoryDoorForMenu(){
    try{
      if(!isStoryDoorOpen() || !window.TTStories) return;
      if(typeof window.TTStories.holdForMenu === 'function') window.TTStories.holdForMenu();
      else if(typeof window.TTStories.pause === 'function') window.TTStories.pause();
    }catch(ePause){}
  }

  function resumeStoryDoorAfterMenu(){
    try{
      if(window.__pcmMentionFromStory || window.__pcmTagFromStory) return;
      if(!isStoryDoorOpen() || !window.TTStories) return;
      if(typeof window.TTStories.clearCloseAfterSlide === 'function') window.TTStories.clearCloseAfterSlide();
      if(typeof window.TTStories.resume === 'function') window.TTStories.resume();
    }catch(eResume){}
  }

  function playStoryThroughThenClose(){
    try{
      if(!isStoryDoorOpen() || !window.TTStories) return;
      if(typeof window.TTStories.playThroughThenClose === 'function'){
        window.TTStories.playThroughThenClose();
      } else if(typeof window.TTStories.resume === 'function'){
        window.TTStories.resume();
      }
    }catch(ePlay){}
  }

  function closePcmMentionSheet(opts){
    opts = opts || {};
    var fromStory = !!window.__pcmMentionFromStory;
    window.__pcmMentionPostId = 0;
    pcmMentionSelected = {};
    hideModal('pcmMentionSheet');
    var input = document.getElementById('pcmMentionPeopleInput');
    if(input) input.value = '';
    var chips = document.getElementById('pcmMentionPeopleChips');
    if(chips) chips.innerHTML = '';
    if(fromStory){
      window.__pcmMentionFromStory = false;
      if(opts.afterSend){
        playStoryThroughThenClose();
      } else {
        resumeStoryDoorAfterMenu();
      }
    }
  }

  function renderPcmMentionChips(){
    var wrap = document.getElementById('pcmMentionPeopleChips');
    if(!wrap) return;
    wrap.innerHTML = '';
    Object.keys(pcmMentionSelected).forEach(function(id){
      var u = pcmMentionSelected[id];
      if(!u) return;
      var chip = document.createElement('span');
      chip.className = 'msb-tag-chip';
      chip.innerHTML = '@' + String(u.username || id).replace(/</g,'&lt;') + ' <button type="button" aria-label="Remove">&times;</button>';
      chip.querySelector('button').addEventListener('click', function(){
        delete pcmMentionSelected[id];
        renderPcmMentionChips();
      });
      wrap.appendChild(chip);
    });
  }

  function addPcmMentionUser(u){
    if(!u || !u.id) return;
    pcmMentionSelected[String(u.id)] = {
      id: Number(u.id),
      username: String(u.username || ''),
      name: String(u.name || ''),
      image: String(u.image || '')
    };
    renderPcmMentionChips();
    var input = document.getElementById('pcmMentionPeopleInput');
    if(input) input.value = '';
  }

  function ensurePcmMentioner(){
    var input = document.getElementById('pcmMentionPeopleInput');
    if(!input) return;
    if(window.MSBMentionAC && typeof window.MSBMentionAC.bind === 'function'){
      window.MSBMentionAC.bind(input, addPcmMentionUser);
      input.setAttribute('data-msb-mention', '1');
    }
  }

  function openPcmMentionSheet(postId){
    postId = Number(postId || 0);
    if(postId <= 0) return false;
    var dialog = document.getElementById('pcmMentionSheet');
    if(!dialog){
      pcmToast('Mention sheet unavailable.');
      return false;
    }
    window.__pcmMentionPostId = postId;
    pcmMentionSelected = {};
    renderPcmMentionChips();
    var hid = document.getElementById('pcmMentionPostId');
    if(hid) hid.value = String(postId);
    var input = document.getElementById('pcmMentionPeopleInput');
    if(input) input.value = '';
    ensurePcmMentioner();
    showModal('pcmMentionSheet');
    ensurePcmMentioner();
    try { if(input) input.focus(); } catch(e) {}
    return true;
  }

  function sendPcmMentions(){
    var postId = Number(window.__pcmMentionPostId || 0);
    if(postId <= 0){
      closePcmMentionSheet();
      return;
    }
    var ids = Object.keys(pcmMentionSelected).map(function(k){ return Number(k); }).filter(function(n){ return n > 0; });
    var input = document.getElementById('pcmMentionPeopleInput');
    var mentionText = input ? String(input.value || '').trim() : '';
    if(!ids.length && !mentionText){
      pcmToast('Pick someone to mention.');
      return;
    }
    var body = new URLSearchParams();
    body.set('action', 'mention');
    body.set('post_id', String(postId));
    body.set('user_ids', ids.join(','));
    if (mentionText) body.set('mention_text', mentionText);
    var btn = document.getElementById('pcmMentionSendBtn');
    if(btn){ btn.disabled = true; btn.style.opacity = '.7'; }
    fetch('ajax/post_tags_save.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: body
    }).then(function(r){ return r.json(); }).then(function(data){
      if(btn){ btn.disabled = false; btn.style.opacity = ''; }
      if(!data || !data.ok){
        var err = data && data.error ? String(data.error) : '';
        pcmToast(err === 'no_people' ? 'Pick someone to mention.' : (err ? ('Could not send mention (' + err + ').') : 'Could not send mention.'));
        return;
      }
      closePcmMentionSheet({ afterSend: true });
      pcmToast(String(data.message || 'Mention sent.'));
      try{
        if(window.MSBNotificationsUI && typeof window.MSBNotificationsUI.refresh === 'function'){
          window.MSBNotificationsUI.refresh();
        }
      }catch(_e){}
    }).catch(function(){
      if(btn){ btn.disabled = false; btn.style.opacity = ''; }
      pcmToast('Network error while sending mention.');
    });
  }

  function openPcmPostDestDialog(postId){
    postId = Number(postId || 0);
    if(!postId) return;
    var input = document.getElementById('pcmPostSourceId');
    if(input) input.value = String(postId);
    showModal('pcmPostDestDialog');
  }

  function closePcmPostDestDialog(){
    hideModal('pcmPostDestDialog');
    var input = document.getElementById('pcmPostSourceId');
    if(input) input.value = '0';
  }

  function submitPcmPostDest(visibility){
    var input = document.getElementById('pcmPostSourceId');
    var postId = Number((input && input.value) || 0);
    visibility = String(visibility || 'friends').toLowerCase();
    if(visibility !== 'public') visibility = 'friends';
    if(!postId){
      closePcmPostDestDialog();
      return;
    }
    var friendsBtn = document.getElementById('pcmPostToFriendsBtn');
    var publicBtn = document.getElementById('pcmPostToPublicBtn');
    if(friendsBtn) friendsBtn.disabled = true;
    if(publicBtn) publicBtn.disabled = true;
    var body = new URLSearchParams({
      post_id: String(postId),
      visibility: visibility
    });
    fetch('ajax/post_repost.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body,
      credentials: 'same-origin'
    }).then(function(r){ return r.json(); }).then(function(res){
      if(friendsBtn) friendsBtn.disabled = false;
      if(publicBtn) publicBtn.disabled = false;
      if(!res || !res.ok){
        pcmToast((res && res.error) ? String(res.error) : 'Unable to repost.');
        return;
      }
      closePcmPostDestDialog();
      pcmToast(String(res.message || (visibility === 'public' ? 'Reposted to Public.' : 'Reposted to Friends.')));
      var redirect = String(res.redirect || '').trim();
      if(redirect){
        window.setTimeout(function(){ window.location.href = redirect; }, 350);
      }
    }).catch(function(){
      if(friendsBtn) friendsBtn.disabled = false;
      if(publicBtn) publicBtn.disabled = false;
      pcmToast('Unable to repost.');
    });
  }

  function openPcmShareSheet(postId, wrap){
    postId = Number(postId || 0);
    if(!postId) return false;
    var dialog = document.getElementById('pcmShareSheet');
    if(!dialog) return false;
    var url = absoluteShareUrl(postId);
    var title = shareTitleForPost(postId);
    var text = title + '\n' + url;
    window.__pcmSharePostId = postId;
    window.__pcmShareUrl = url;
    window.__pcmShareTitle = title;

    // Prefill real hrefs so the browser opens apps even if JS is delayed.
    var map = {
      facebook: 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url),
      whatsapp: 'https://api.whatsapp.com/send?text=' + encodeURIComponent(text),
      x: 'https://twitter.com/intent/tweet?url=' + encodeURIComponent(url) + '&text=' + encodeURIComponent(title),
      twitter: 'https://twitter.com/intent/tweet?url=' + encodeURIComponent(url) + '&text=' + encodeURIComponent(title),
      telegram: 'https://t.me/share/url?url=' + encodeURIComponent(url) + '&text=' + encodeURIComponent(title),
      email: 'mailto:?subject=' + encodeURIComponent(title) + '&body=' + encodeURIComponent(text),
      messages: 'sms:?&body=' + encodeURIComponent(text),
      instagram: 'https://www.instagram.com/',
      tiktok: 'https://www.tiktok.com/'
    };
    dialog.querySelectorAll('[data-pcm-share]').forEach(function(btn){
      var key = String(btn.getAttribute('data-pcm-share') || '').toLowerCase();
      if(map[key]){
        btn.setAttribute('href', map[key]);
        btn.setAttribute('target', '_blank');
        btn.setAttribute('rel', 'noopener noreferrer');
      } else {
        btn.removeAttribute('href');
        btn.removeAttribute('target');
      }
    });

    var nativeBtn = document.getElementById('pcmShareNativeBtn');
    if(nativeBtn){
      var canNative = !!(navigator.share && typeof navigator.share === 'function');
      if(canNative) nativeBtn.removeAttribute('hidden');
      else nativeBtn.setAttribute('hidden', '');
    }
    setTimeout(function(){
      if(Number(window.__pcmSharePostId || 0) !== postId) return;
      try{
        if(dialog.parentNode !== document.body) document.body.appendChild(dialog);
        if(typeof dialog.showModal === 'function'){
          if(!dialog.open) dialog.showModal();
        }else{
          dialog.setAttribute('open', '');
        }
      }catch(eOpen){
        try{ dialog.setAttribute('open', ''); }catch(e2){}
      }
    }, 0);
    return true;
  }

  function openExternalShare(href){
    href = String(href || '');
    if(!href) return false;
    // Anchor click is more reliable than window.open from inside <dialog>.
    try{
      var a = document.createElement('a');
      a.href = href;
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.style.display = 'none';
      document.body.appendChild(a);
      a.click();
      setTimeout(function(){ try{ a.remove(); }catch(e){} }, 0);
      return true;
    }catch(e){}
    try{
      var win = window.open(href, '_blank');
      if(win) return true;
    }catch(e2){}
    try{
      window.location.href = href;
      return true;
    }catch(e3){}
    return false;
  }

  function runShareTarget(target, evt){
    var postId = Number(window.__pcmSharePostId || 0);
    var url = String(window.__pcmShareUrl || '');
    if(!postId || !url) return;
    target = String(target || '').toLowerCase();
    var title = String(window.__pcmShareTitle || shareTitleForPost(postId));
    var text = title + '\n' + url;
    var href = '';

    function finish(msg){
      recordShareOnce(postId);
      // Close after navigation starts so popup blockers do not cancel the open.
      setTimeout(function(){
        closePcmShareSheet();
        if(msg) pcmToast(msg);
      }, 120);
    }

    if(target === 'native'){
      if(evt && evt.preventDefault) evt.preventDefault();
      if(!(navigator.share && typeof navigator.share === 'function')){
        pcmToast('Sharing is not available on this device.');
        return;
      }
      var payload = { title: title, text: title, url: url };
      var mediaSrc = shareMediaSrcForPost(postId);
      var sharePromise = Promise.resolve();
      if(mediaSrc && navigator.canShare){
        sharePromise = fetch(mediaSrc, { credentials: 'same-origin' })
          .then(function(r){ return r.ok ? r.blob() : null; })
          .then(function(blob){
            if(!blob) return;
            var name = 'talentra-post-' + postId + (blob.type.indexOf('video') === 0 ? '.mp4' : '.jpg');
            var file = new File([blob], name, { type: blob.type || 'image/jpeg' });
            var withFile = { title: title, text: title, url: url, files: [file] };
            if(navigator.canShare(withFile)) payload = withFile;
          })
          .catch(function(){});
      }
      sharePromise.then(function(){
        return navigator.share(payload);
      }).then(function(){
        finish('Shared.');
      }).catch(function(err){
        if(err && String(err.name || '') === 'AbortError') return;
        // Fallback to URL-only share.
        navigator.share({ title: title, text: title, url: url }).then(function(){
          finish('Shared.');
        }).catch(function(){
          pcmToast('Could not open share sheet.');
        });
      });
      return;
    }

    if(target === 'copy'){
      if(evt && evt.preventDefault) evt.preventDefault();
      copyText(url).then(function(){
        finish('Link copied to clipboard.');
      }).catch(function(){
        pcmToast('Could not copy link.');
      });
      return;
    }

    if(target === 'instagram' || target === 'tiktok'){
      // Those apps have no reliable web composer — copy the post link, then open the app/site.
      if(evt && evt.preventDefault) evt.preventDefault();
      href = target === 'instagram' ? 'https://www.instagram.com/' : 'https://www.tiktok.com/';
      copyText(url).then(function(){
        openExternalShare(href);
        finish(target === 'instagram'
          ? 'Link copied. Paste it in an Instagram post, Reel, or Story.'
          : 'Link copied. Paste it in a TikTok post or message.');
      }).catch(function(){
        openExternalShare(href);
        finish('Opened ' + (target === 'instagram' ? 'Instagram' : 'TikTok') + '. Copy the post link to share.');
      });
      return;
    }

    if(target === 'facebook'){
      href = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url);
    } else if(target === 'whatsapp'){
      href = 'https://api.whatsapp.com/send?text=' + encodeURIComponent(text);
    } else if(target === 'x' || target === 'twitter'){
      href = 'https://twitter.com/intent/tweet?url=' + encodeURIComponent(url) + '&text=' + encodeURIComponent(title);
    } else if(target === 'telegram'){
      href = 'https://t.me/share/url?url=' + encodeURIComponent(url) + '&text=' + encodeURIComponent(title);
    } else if(target === 'email'){
      href = 'mailto:?subject=' + encodeURIComponent(title) + '&body=' + encodeURIComponent(text);
    } else if(target === 'messages' || target === 'sms' || target === 'imessage'){
      href = 'sms:?&body=' + encodeURIComponent(text);
    }

    if(!href) return;

    // If the button is already an <a href>, let the browser follow it (most reliable).
    var isAnchorNav = !!(evt && evt.currentTarget && evt.currentTarget.tagName === 'A' && evt.currentTarget.getAttribute('href'));
    if(isAnchorNav){
      // Still force open for mailto/sms in case dialog swallows navigation.
      if(target === 'email' || target === 'messages' || target === 'sms' || target === 'imessage'){
        if(evt && evt.preventDefault) evt.preventDefault();
        try{ window.location.href = href; }catch(eLoc){}
      }
      finish(target === 'facebook' ? 'Opening Facebook…'
        : (target === 'whatsapp' ? 'Opening WhatsApp…'
        : (target === 'x' || target === 'twitter' ? 'Opening X…'
        : (target === 'telegram' ? 'Opening Telegram…'
        : (target === 'email' ? 'Opening Mail…' : 'Opening Messages…')))));
      return;
    }

    if(evt && evt.preventDefault) evt.preventDefault();
    if(target === 'email' || target === 'messages' || target === 'sms' || target === 'imessage'){
      try{ window.location.href = href; }catch(eSms){}
    } else {
      openExternalShare(href);
    }
    finish(target === 'facebook' ? 'Opening Facebook…'
      : (target === 'whatsapp' ? 'Opening WhatsApp…'
      : (target === 'x' || target === 'twitter' ? 'Opening X…'
      : (target === 'telegram' ? 'Opening Telegram…'
      : (target === 'email' ? 'Opening Mail…' : 'Opening Messages…')))));
  }

  function copyText(text){
    if(navigator.clipboard && navigator.clipboard.writeText){
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function(resolve, reject){
      try {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        resolve();
      } catch(err){ reject(err); }
    });
  }

  function pcmToast(msg){
    var el = document.getElementById('pcmActionToast');
    if(!el){
      el = document.createElement('div');
      el.id = 'pcmActionToast';
      el.setAttribute('role', 'status');
      el.style.cssText = 'position:fixed;left:50%;bottom:28px;transform:translateX(-50%);background:#262626;color:#fff;padding:10px 16px;border-radius:999px;font-size:13px;font-weight:600;z-index:100001;opacity:0;transition:opacity .2s ease;pointer-events:none;';
      document.body.appendChild(el);
    }
    el.textContent = String(msg || '');
    el.style.opacity = '1';
    clearTimeout(el._hideTimer);
    el._hideTimer = setTimeout(function(){ el.style.opacity = '0'; }, 1800);
  }

  function syncBookmarkMenuState(postId, saved){
    postId = Number(postId || 0);
    saved = !!saved;
    document.querySelectorAll('.pcm-bookmark[data-post-id="'+String(postId)+'"]').forEach(function(btn){
      btn.classList.toggle('is-active', saved);
      btn.setAttribute('data-saved', saved ? '1' : '0');
      var icon = btn.querySelector('i');
      if(icon){
        icon.className = saved ? 'fa fa-bookmark' : 'fa fa-bookmark-o';
      }
    });
  }

  function syncPostTrackState(postId, res){
    postId = Number(postId || 0);
    if(!postId || !res) return;

    var state = res.state || {};
    var counts = res.counts || res;
    var saved = Number(
      state.saved != null ? state.saved :
      (counts.is_saved != null ? counts.is_saved :
      (counts.my_saved != null ? counts.my_saved : 0))
    ) === 1;
    var shared = Number(
      state.shared != null ? state.shared :
      (counts.is_shared != null ? counts.is_shared :
      (counts.my_shared != null ? counts.my_shared : 0))
    ) === 1;

    document.querySelectorAll('.mf-card[data-id="'+String(postId)+'"], .public-post-card[data-post-id="'+String(postId)+'"]').forEach(function(card){
      card.setAttribute('data-my-saved', saved ? '1' : '0');
    });

    document.querySelectorAll('.js-save-post[data-post-id="'+String(postId)+'"], .mf-card[data-id="'+String(postId)+'"] .mf-act.mf-save').forEach(function(btn){
      btn.classList.toggle('is-save', saved);
    });
    document.querySelectorAll('.js-share-post[data-post-id="'+String(postId)+'"], .mf-card[data-id="'+String(postId)+'"] .mf-act.mf-share').forEach(function(btn){
      btn.classList.toggle('is-share', shared);
    });
    document.querySelectorAll('.mf-card[data-id="'+String(postId)+'"] .mf-act.mf-save .msb-pact-bookmark').forEach(function(icon){
      icon.classList.toggle('is-active', saved);
    });

    if(typeof res.share_count !== 'undefined'){
      document.querySelectorAll('.public-post-card[data-post-id="'+String(postId)+'"] .js-share-count').forEach(function(el){
        el.textContent = String(res.share_count || 0);
      });
      document.querySelectorAll('.mf-card[data-id="'+String(postId)+'"] .mf-act.mf-share .mf-num').forEach(function(el){
        el.textContent = String(res.share_count || 0);
      });
    }
    if(typeof res.save_count !== 'undefined'){
      document.querySelectorAll('.public-post-card[data-post-id="'+String(postId)+'"] .js-save-count').forEach(function(el){
        el.textContent = String(res.save_count || 0);
      });
      document.querySelectorAll('.mf-card[data-id="'+String(postId)+'"] .mf-act.mf-save .mf-num').forEach(function(el){
        el.textContent = String(res.save_count || 0);
      });
    }

    syncBookmarkMenuState(postId, saved);

    if(typeof window.mfHydrateCard === 'function'){
      try {
        window.mfHydrateCard(postId, res.post || {}, {
          save_count: res.save_count,
          share_count: res.share_count,
          is_saved: saved ? 1 : 0,
          is_shared: shared ? 1 : 0
        }, res.attachments || []);
      } catch(e){}
    }

    if(window.MSBPostEngagement && typeof window.MSBPostEngagement.publishFromTrack === 'function'){
      try {
        window.MSBPostEngagement.publishFromTrack(postId, {
          share_count: res.share_count,
          save_count: res.save_count,
          state: { shared: shared ? 1 : 0, saved: saved ? 1 : 0 }
        }, { source: 'post-menu' });
      } catch(e){}
    }
  }

  function postTrack(action, postId, done, extra){
    postId = Number(postId || 0);
    if(!postId) return;
    extra = extra || {};
    var url = trackApiUrl();
    var payload = { ajax: action, post_id: postId };
    if(action === 'save' && extra.fromStory) payload.from_story = 1;
    if($){
      $.post(url, payload, function(res){
        if(res && res.ok !== false) syncPostTrackState(postId, res);
        if(typeof done === 'function') done(res);
      }, 'json');
      return;
    }
    var body = new URLSearchParams(payload);
    fetch(url, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body })
      .then(function(r){ return r.json(); })
      .then(function(res){
        if(res && res.ok !== false) syncPostTrackState(postId, res);
        if(typeof done === 'function') done(res);
      })
      .catch(function(){ if(typeof done === 'function') done(null); });
  }

  function buildOwnerItems(it, pid, helpers){
    helpers = helpers || {};
    var esc = helpers.esc || escHtml;
    pid = Number(pid || it.id || 0);
    if(pid <= 0) return '';

    var isArchived = Number(
      it.is_archived != null ? it.is_archived :
      (it.my_archived != null ? it.my_archived : 0)
    ) === 1;
    var vis = normalizeVisibility(it.visibility || 'friends');
    var editHref = 'dashboard.php?modal=1&edit=' + String(pid);
    var html = linkItem('pcm-edit', editHref, 'fa fa-edit', 'Edit', ' data-create-post-modal="1"');
    html += buttonItem('pcm-tag', 'fa fa-at', 'Tag', ' data-post-id="'+esc(String(pid))+'"');
    html += buttonItem('pcm-mention', 'fa fa-bullhorn', 'Mention', ' data-post-id="'+esc(String(pid))+'"');
    if(vis !== 'private'){
      html += buttonItem('pcm-private', 'fa fa-lock', 'Private', ' data-post-id="'+esc(String(pid))+'" data-visibility="private"');
    }
    html += buttonItem(
      'pcm-archive',
      'fa fa-archive',
      isArchived ? 'Unarchive' : 'Archive',
      ' data-post-id="'+esc(String(pid))+'" data-archived="'+(isArchived ? '1' : '0')+'"'
    );
    html += buttonItem('pcm-delete', 'fa fa-trash', 'Delete', ' data-post-id="'+esc(String(pid))+'"');
    return html;
  }

  function buildCommonItems(it, isOwner, pid, helpers){
    helpers = helpers || {};
    var esc = helpers.esc || escHtml;
    pid = Number(pid || it.id || 0);
    if(pid <= 0) return '';

    var isSaved = Number(it.my_saved != null ? it.my_saved : (it.is_saved != null ? it.is_saved : 0)) === 1;
    var meTagged = Number(it.me_tagged != null ? it.me_tagged : 0) === 1;
    var friendStatus = String(it.friend_status || helpers.friendStatus || 'none').toLowerCase();
    var menuSurface = String(opts.menu_surface || helpers.menuSurface || 'public').toLowerCase();
    var isDiscoverOrReel = (menuSurface === 'public' || menuSurface === 'reel');
    var isStranger = !isOwner && friendStatus !== 'friends' && friendStatus !== 'self';
    var canSelfTag = !isOwner && (meTagged || friendStatus === 'friends' || String(it.visibility || 'friends') === 'public');
    // Discover / Reels strangers: Mention only — no Tag / Add to Tags.
    if(isDiscoverOrReel && isStranger) canSelfTag = false;
    var html = '';

    if(!isOwner){
      html += buttonItem('pcm-report is-danger', 'fa fa-flag', 'Report', ' data-post-id="'+esc(String(pid))+'"');
      if(canSelfTag || (meTagged && !(isDiscoverOrReel && isStranger))){
        html += buttonItem(
          'pcm-tag-self' + (meTagged ? ' is-active' : ''),
          'fa fa-at',
          meTagged ? 'Remove from Tags' : 'Add to Tags',
          ' data-post-id="'+esc(String(pid))+'" data-me-tagged="'+(meTagged ? '1' : '0')+'"'
        );
      }
      html += buttonItem('pcm-mention', 'fa fa-bullhorn', 'Mention', ' data-post-id="'+esc(String(pid))+'"');
    }
    html += buttonItem('pcm-post', 'fa fa-retweet', 'Repost', ' data-post-id="'+esc(String(pid))+'"');
    html += buttonItem(
      'pcm-bookmark' + (isSaved ? ' is-active' : ''),
      isSaved ? 'fa fa-bookmark' : 'fa fa-bookmark-o',
      'Bookmark',
      ' data-post-id="'+esc(String(pid))+'" data-saved="'+(isSaved ? '1' : '0')+'"'
    );
    html += buttonItem('pcm-share', 'fa fa-share', 'Share', ' data-post-id="'+esc(String(pid))+'"');
    html += buttonItem('pcm-copy-link', 'fa fa-link', 'Copy link', ' data-post-id="'+esc(String(pid))+'"');
    return html;
  }

  function buildViewPostItem(pid, helpers){
    helpers = helpers || {};
    var esc = helpers.esc || escHtml;
    pid = Number(pid || 0);
    if(pid <= 0) return '';
    return buttonItem('pcm-view-post', 'fa fa-expand', 'View the post', ' data-post-id="'+esc(String(pid))+'"');
  }

  function menuItemSel(suffix){
    return '.post-card-menu ' + suffix + ', .mf-menu.post-card-menu ' + suffix + ', .pcm-menu-portal ' + suffix;
  }

  function followMenuLink(linkEl){
    var href = String(linkEl.getAttribute('href') || '').trim();
    if(!href) return;
    closeMenus();
    window.location.href = href;
  }

  function isFeedSurface(){
    return String(opts.menu_surface || 'public') === 'feed';
  }

  function resolveIsOwner(it, isOwner){
    isOwner = !!isOwner;
    var meId = Number(window.ME_ID || window.__MSB_FEED_ME_ID || 0);
    var userId = Number(it.user_id || it.author_id || 0);
    if(String(it.friend_status || '') === 'self') return true;
    if(meId > 0 && userId > 0 && userId === meId) return true;
    return isOwner;
  }

  function refreshFeedCardMenus(root){
    var surface = String(opts.menu_surface || 'public');
    if(surface !== 'feed' && surface !== 'profile') return;
    root = root || document;
    var cards = root.querySelectorAll
      ? root.querySelectorAll('.mf-card')
      : document.querySelectorAll((surface === 'profile' ? '#profilePostsFeed ' : '') + '.mf-card');
    if(root && root.nodeType === 1 && root.classList && root.classList.contains('mf-card')){
      cards = [root];
    }
    cards.forEach(function(card){
      var wrap = card.querySelector('.post-card-menu-wrap, .mf-menu-wrap.post-card-menu-wrap');
      var menu = wrap && wrap.querySelector('.post-card-menu, .mf-menu.post-card-menu');
      if(!wrap || !menu) return;
      var it = itemFromCard(card);
      if(!it) return;
      var pid = Number(wrap.getAttribute('data-post-id') || card.getAttribute('data-id') || card.getAttribute('data-post-id') || 0);
      var isOwner = resolveIsOwner(it, String(wrap.getAttribute('data-is-owner') || card.getAttribute('data-post-owner') || '0') === '1');
      wrap.setAttribute('data-is-owner', isOwner ? '1' : '0');
      card.setAttribute('data-post-owner', isOwner ? '1' : '0');
      var html = buildItems(it, isOwner, pid, getMenuHelpers(pid));
      menu.innerHTML = html || '';
      wrap.style.display = html ? '' : 'none';
      if(!html){
        closeMenus();
      }
    });
  }

  function buildItems(it, isOwner, pid, helpers){
    helpers = helpers || {};
    var esc = helpers.esc || escHtml;
    var profileHrefFn = helpers.profileHref;
    var isPublisherFn = helpers.isPublisher;
    var isFollowingFn = helpers.isFollowing;
    var friendStatusFn = helpers.friendStatus;

    it = it || {};
    pid = Number(pid || it.id || 0);
    isOwner = resolveIsOwner(it, isOwner);
    var staffReadonly = !!opts.staff_readonly;
    var viewPostHtml = buildViewPostItem(pid, helpers);

    if(isOwner && !staffReadonly){
      var ownerHtml = buildOwnerItems(it, pid, helpers);
      var ownerCommon = buildCommonItems(it, true, pid, helpers);
      if(ownerCommon) ownerHtml += menuDivider() + ownerCommon;
      if(viewPostHtml){
        ownerHtml = viewPostHtml + (ownerHtml ? menuDivider() + ownerHtml : '');
      }
      return ownerHtml;
    }

    var peerId = Number(it.user_id || 0);
    var friendCode = String(it.friend_code || '').trim();
    var friendStatus = friendStatusFn ? String(friendStatusFn(it) || 'none') : String(it.friend_status || 'none');
    var isPublisher = isPublisherFn ? !!isPublisherFn(it) : (Number(it.is_publisher || 0) === 1 || String(it.account_kind || '') === 'publisher');
    var isFollowing = isFollowingFn ? !!isFollowingFn(it) : Number(it.is_following || 0) === 1;
    var profileUrl = profileHrefFn ? String(profileHrefFn(it, pid) || '') : String(it.profile_url || '');
    if(!profileUrl && peerId > 0){
      if(friendCode){
        profileUrl = 'profile.php?friend_code=' + encodeURIComponent(friendCode.toUpperCase());
      } else if(String(it.username || '').trim()){
        profileUrl = 'profile.php?username=' + encodeURIComponent(String(it.username).trim());
      } else {
        profileUrl = 'profile.php?id=' + String(peerId);
      }
    }
    var messageUrl = friendCode ? ('messages.php?peer=' + encodeURIComponent(friendCode)) : (peerId ? ('messages.php?peer_id=' + peerId) : 'messages.php');
    var html = viewPostHtml;
    var feedSurface = isFeedSurface();
    var canFollowPublishers = opts.can_follow_publishers !== false;
    var publisherWorkspaceViewer = !!opts.publisher_workspace_viewer;
    // Personal users may always open a publisher profile (Posts / Gallery / Tags).
    var showPublisherView = isPublisher && (isFollowing || canFollowPublishers) && profileUrl;

    if((!feedSurface || !isPublisher || showPublisherView) && profileUrl){
      html += linkItem('pcm-view', profileUrl, 'fa fa-user', 'View');
    }
    if(friendStatus === 'friends'){
      html += linkItem('pcm-message', messageUrl, 'fa fa-comments', 'Message');
    }
    if(!isPublisher && peerId && friendStatus === 'friends' && !staffReadonly){
      html += '<button type="button" class="pcm-item pcm-unfriend is-danger" data-peer-id="' + esc(String(peerId)) + '" role="menuitem">' +
        '<i class="fa fa-user-times" aria-hidden="true"></i><span>Unfriend</span></button>';
    }
    if(!feedSurface && !isPublisher && peerId && friendStatus === 'none' && !staffReadonly){
      html += '<button type="button" class="pcm-item pcm-add-friend" data-peer-id="' + esc(String(peerId)) + '" role="menuitem">' +
        '<i class="fa fa-user-plus" aria-hidden="true"></i><span>Add Friend</span></button>';
    }
    if(!feedSurface && isPublisher && !isFollowing && peerId && canFollowPublishers){
      html += '<button type="button" class="pcm-item pcm-follow" data-publisher-id="' + esc(String(peerId)) + '" role="menuitem">' +
        '<i class="fa fa-user-plus" aria-hidden="true"></i><span>Follow</span></button>';
    }
    if(isPublisher && isFollowing && peerId){
      html += '<button type="button" class="pcm-item pcm-unfollow" data-publisher-id="' + esc(String(peerId)) + '" role="menuitem">' +
        '<i class="fa fa-user-times" aria-hidden="true"></i><span>Unfollow</span></button>';
    }
    var showTimeline = !feedSurface && peerId > 0 && (!isPublisher || publisherWorkspaceViewer);
    if(showTimeline){
      html += linkItem('pcm-timeline', 'timeline.php?u=' + String(peerId), 'icon ion-ios-locked', 'Timeline');
    }

    var commonHtml = buildCommonItems(it, isOwner, pid, helpers);
    if(commonHtml){
      if(html) html += menuDivider();
      html += commonHtml;
    }

    return html;
  }

  function getMenuHelpers(pid){
    var helpers = window.MSBProfileMenuHelpers || window.MSBFeedMenuHelpers || window.MSBPublicMenuHelpers || {};
    if(!helpers.profileHref || pid == null) return helpers;
    var baseProfileHref = helpers.profileHref;
    return {
      esc: helpers.esc,
      profileHref: function(it){ return baseProfileHref(it, pid); },
      isPublisher: helpers.isPublisher,
      isFollowing: helpers.isFollowing,
      friendStatus: helpers.friendStatus
    };
  }

  function itemFromCard(card){
    if(!card) return null;
    var stage = (card.classList && card.classList.contains('reel-stage'))
      ? card
      : (card.querySelector ? card.querySelector('.reel-stage') : null);
    var wrap = card.querySelector ? card.querySelector('.post-card-menu-wrap, .mf-menu-wrap') : null;
    var host = stage || card;
    return {
      id: Number(host.getAttribute('data-post-id') || card.getAttribute('data-post-id') || card.getAttribute('data-id') || (wrap && wrap.getAttribute('data-post-id')) || 0),
      user_id: Number(host.getAttribute('data-peer-id') || card.getAttribute('data-peer-id') || (wrap && wrap.getAttribute('data-peer-id')) || 0),
      author_id: Number(host.getAttribute('data-peer-id') || card.getAttribute('data-peer-id') || (wrap && wrap.getAttribute('data-peer-id')) || 0),
      friend_code: String(host.getAttribute('data-peer-code') || card.getAttribute('data-peer-code') || (wrap && wrap.getAttribute('data-peer-code')) || ''),
      username: String(card.getAttribute('data-peer-username') || ''),
      account_kind: String(host.getAttribute('data-account-kind') || card.getAttribute('data-account-kind') || 'personal'),
      is_following: Number(host.getAttribute('data-is-following') || card.getAttribute('data-is-following') || 0),
      friend_status: String(host.getAttribute('data-friend-status') || card.getAttribute('data-friend-status') || 'none'),
      is_publisher: Number(host.getAttribute('data-is-publisher') || card.getAttribute('data-is-publisher') || 0),
      contact_id: Number(card.getAttribute('data-contact-id') || 0),
      contact_name: String(card.getAttribute('data-contact-name') || ''),
      profile_url: String(host.getAttribute('data-profile-url') || card.getAttribute('data-profile-url') || ''),
      my_saved: Number(host.getAttribute('data-my-saved') || card.getAttribute('data-my-saved') || 0),
      is_saved: Number(host.getAttribute('data-my-saved') || card.getAttribute('data-my-saved') || 0),
      is_archived: Number(host.getAttribute('data-is-archived') || card.getAttribute('data-is-archived') || 0),
      my_archived: Number(host.getAttribute('data-is-archived') || card.getAttribute('data-is-archived') || 0),
      visibility: normalizeVisibility(host.getAttribute('data-visibility') || card.getAttribute('data-visibility') || 'friends')
    };
  }

  function rebuildCardFriendMenu(card, status){
    if(!card) return;
    status = String(status || 'none');
    card.setAttribute('data-friend-status', status);
    var stage = (card.classList && card.classList.contains('reel-stage'))
      ? card
      : (card.querySelector ? card.querySelector('.reel-stage') : null);
    if(stage) stage.setAttribute('data-friend-status', status);
    var slide = (card.classList && card.classList.contains('reel-slide'))
      ? card
      : (card.closest ? card.closest('.reel-slide') : null);
    var menuRoot = slide || card;
    var menu = menuRoot.querySelector('.mf-menu.post-card-menu, .post-card-menu');
    if(!menu) return;
    var wrap = menuRoot.querySelector('.post-card-menu-wrap, .mf-menu-wrap');
    var it = itemFromCard(stage || card);
    it.friend_status = status;
    var pid = Number((wrap && wrap.getAttribute('data-post-id')) || (stage && stage.getAttribute('data-post-id')) || card.getAttribute('data-id') || card.getAttribute('data-post-id') || 0);
    var isOwner = resolveIsOwner(it, String((wrap && wrap.getAttribute('data-is-owner')) || (stage && stage.getAttribute('data-post-owner')) || card.getAttribute('data-post-owner') || '0') === '1');
    var html = buildItems(it, isOwner, pid, getMenuHelpers(pid));
    if(html) menu.innerHTML = html;
  }

  function syncCardFriend(cardOrJq, status){
    if($ && cardOrJq && cardOrJq.jquery){
      cardOrJq.each(function(){
        rebuildCardFriendMenu(this, status);
      });
      return;
    }
    if(cardOrJq && cardOrJq.nodeType === 1){
      rebuildCardFriendMenu(cardOrJq, status);
    }
  }

  function syncFriendCards(peerId, status){
    peerId = Number(peerId || 0);
    if(peerId <= 0) return;
    status = String(status || 'none');
    document.querySelectorAll('.mf-card[data-peer-id="'+String(peerId)+'"], .post.public-post-card[data-peer-id="'+String(peerId)+'"]').forEach(function(card){
      if(Number(card.getAttribute('data-is-publisher') || 0) === 1) return;
      rebuildCardFriendMenu(card, status);
    });
    document.querySelectorAll('.reel-slide').forEach(function(slide){
      var stage = slide.querySelector('.reel-stage');
      var wrap = slide.querySelector('.post-card-menu-wrap');
      var slidePeer = Number((stage && stage.getAttribute('data-peer-id')) || (wrap && wrap.getAttribute('data-peer-id')) || 0);
      if(slidePeer !== peerId) return;
      if(Number((stage && stage.getAttribute('data-is-publisher')) || 0) === 1) return;
      rebuildCardFriendMenu(slide, status);
    });
    var pvWrap = document.getElementById('pvMenuWrap');
    if(pvWrap && Number(pvWrap.getAttribute('data-peer-id') || 0) === peerId){
      pvWrap.setAttribute('data-friend-status', status);
      if(typeof window.pvSyncMenu === 'function'){
        try { window.pvSyncMenu(); } catch(e){}
      } else {
        rebuildCardFriendMenu(pvWrap, status);
      }
    }
  }

  function rebuildCardPublisherMenu(card, following){
    if(!card) return;
    following = !!following;
    card.setAttribute('data-is-following', following ? '1' : '0');
    var menu = card.querySelector('.mf-menu.post-card-menu, .post-card-menu');
    if(!menu) return;
    var wrap = card.querySelector('.post-card-menu-wrap, .mf-menu-wrap');
    var it = itemFromCard(card);
    it.is_following = following ? 1 : 0;
    if(following){
      it.account_kind = it.account_kind || 'publisher';
      it.is_publisher = 1;
    }
    it.contact_id = Number(card.getAttribute('data-contact-id') || it.contact_id || 0);
    it.contact_name = String(card.getAttribute('data-contact-name') || it.contact_name || '');
    var pid = Number((wrap && wrap.getAttribute('data-post-id')) || card.getAttribute('data-id') || card.getAttribute('data-post-id') || 0);
    var isOwner = resolveIsOwner(it, String((wrap && wrap.getAttribute('data-is-owner')) || card.getAttribute('data-post-owner') || '0') === '1');
    var html = buildItems(it, isOwner, pid, getMenuHelpers(pid));
    if(html) menu.innerHTML = html;
  }

  function syncCardPublisher(cardOrJq, following){
    if($ && cardOrJq && cardOrJq.jquery){
      cardOrJq.each(function(){
        rebuildCardPublisherMenu(this, following);
      });
      return;
    }
    if(cardOrJq && cardOrJq.nodeType === 1){
      rebuildCardPublisherMenu(cardOrJq, following);
    }
  }

  function syncPublisherCards(pubId, following){
    pubId = Number(pubId || 0);
    if(pubId <= 0) return;
    document.querySelectorAll('.mf-card[data-peer-id="'+String(pubId)+'"], .post.public-post-card[data-peer-id="'+String(pubId)+'"]').forEach(function(card){
      rebuildCardPublisherMenu(card, following);
    });
  }

  function hydrateEmptyMenus(root){
    root = root || document;
    root.querySelectorAll('.post-card-menu-wrap, .mf-menu-wrap.post-card-menu-wrap').forEach(function(wrap){
      var menu = wrap.querySelector('.post-card-menu, .mf-menu.post-card-menu');
      if(!menu || menu.innerHTML.trim() !== '') return;
      var card = wrap.closest('.mf-card, .public-post-card, [data-post-id]');
      if(!card) return;
      var pid = Number(wrap.getAttribute('data-post-id') || card.getAttribute('data-post-id') || card.getAttribute('data-id') || 0);
      var it = itemFromCard(card);
      if(!it) return;
      var isOwner = resolveIsOwner(it, String(wrap.getAttribute('data-is-owner') || card.getAttribute('data-post-owner') || '0') === '1');
      var html = buildItems(it, isOwner, pid, getMenuHelpers(pid));
      if(html) menu.innerHTML = html;
    });
  }

  function toggleMenuBtn(btn){
    var wrap = btn.closest('.post-card-menu-wrap, .mf-menu-wrap');
    if(!wrap) return;
    var menu = wrap.querySelector('.post-card-menu, .mf-menu.post-card-menu');
    if(!menu) return;
    if(!menu.innerHTML.trim()){
      if(isFeedSurface()){
        var card = wrap.closest('.mf-card');
        if(card) refreshFeedCardMenus(card);
      }
      if(!menu.innerHTML.trim()) hydrateEmptyMenus(document);
    }
    var usePortal = shouldUsePortalMenu(wrap);
    var isOpen = usePortal
      ? (wrap.classList.contains('pcm-wrap-open') || (activePortalWrap === wrap && !!activePortal))
      : menu.classList.contains('open');
    if(isOpen){
      closeMenus();
      return;
    }
    closeMenus(menu);
    if(isStoryMenuWrap(wrap)){
      window.__pcmStoryMenuOpen = true;
      pauseStoryDoorForMenu();
    }
    if(usePortal){
      openPortalMenu(wrap, btn, menu);
      return;
    }
    menu.classList.add('open');
    btn.setAttribute('aria-expanded', 'true');
  }

  function showModal(id){
    var el = document.getElementById(id);
    if(!el) return;
    if(String(el.tagName || '').toLowerCase() === 'dialog'){
      if(typeof el.showModal === 'function'){
        if(!el.open) el.showModal();
      }else{
        el.setAttribute('open', '');
      }
      return;
    }
    if($ && $.fn && $.fn.modal){
      $('#' + id).modal('show');
      return;
    }
    el.style.display = 'block';
    el.classList.add('show');
    el.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
    var backdrop = document.querySelector('.pcm-fallback-backdrop');
    if(!backdrop){
      backdrop = document.createElement('div');
      backdrop.className = 'modal-backdrop fade show pcm-fallback-backdrop';
      backdrop.setAttribute('aria-hidden', 'true');
      document.body.appendChild(backdrop);
    }
  }

  function hideModal(id){
    var el = document.getElementById(id);
    if(el && String(el.tagName || '').toLowerCase() === 'dialog'){
      if(el.open && typeof el.close === 'function') el.close();
      else el.removeAttribute('open');
      return;
    }
    if($ && $.fn && $.fn.modal){
      $('#' + id).modal('hide');
      return;
    }
    if(el){
      el.classList.remove('show');
      el.setAttribute('aria-hidden', 'true');
      el.style.display = 'none';
    }
    document.querySelectorAll('.pcm-fallback-backdrop').forEach(function(backdrop){
      try { backdrop.remove(); } catch(e){}
    });
    document.body.classList.remove('modal-open');
  }

  function syncVisibilityOnCards(postId, visibility){
    postId = Number(postId || 0);
    visibility = normalizeVisibility(visibility);
    if(!postId) return;
    var meta = visibilityMeta(visibility);
    var badgeHtml = visibilityBadgeHtml(visibility);
    document.querySelectorAll(
      '.mf-card[data-id="'+String(postId)+'"],' +
      '.mf-card[data-post-id="'+String(postId)+'"],' +
      '.public-post-card[data-post-id="'+String(postId)+'"],' +
      '.reel-stage[data-post-id="'+String(postId)+'"],' +
      '.reel-slide[data-post-id="'+String(postId)+'"]'
    ).forEach(function(card){
      card.setAttribute('data-visibility', visibility);
      var stage = card.querySelector ? card.querySelector('.reel-stage') : null;
      if(stage) stage.setAttribute('data-visibility', visibility);
      card.querySelectorAll('.post-vis-badge, .mf-vis').forEach(function(badge){
        try{
          var parent = badge.parentNode;
          if(!parent) return;
          var tmp = document.createElement('div');
          tmp.innerHTML = badgeHtml;
          var next = tmp.firstChild;
          if(next) parent.replaceChild(next, badge);
        }catch(eRep){}
      });
      var wrap = card.querySelector('.post-card-menu-wrap, .mf-menu-wrap');
      var menu = wrap && wrap.querySelector('.post-card-menu, .mf-menu.post-card-menu');
      if(menu){
        var it = itemFromCard(card);
        it.visibility = visibility;
        var pid = Number((wrap && wrap.getAttribute('data-post-id')) || postId);
        var isOwner = resolveIsOwner(it, String((wrap && wrap.getAttribute('data-is-owner')) || card.getAttribute('data-post-owner') || '0') === '1');
        var html = buildItems(it, isOwner, pid, getMenuHelpers(pid));
        if(html) menu.innerHTML = html;
      }
    });
    return meta;
  }

  function shouldRemoveAfterPrivate(){
    var surface = String(opts.menu_surface || '').toLowerCase();
    if(surface === 'feed' || surface === 'public' || surface === 'reel') return true;
    if(/\/(feed|public|reel)\.php/i.test(String(window.location.pathname || ''))) return true;
    return false;
  }

  function privateConfirmBodyText(){
    var path = String(window.location.pathname || '').toLowerCase();
    var search = String(window.location.search || '').toLowerCase();
    var surface = String(opts.menu_surface || '').toLowerCase();
    var onProfile = /profile\.php/.test(path) || surface === 'profile';
    var onPostsTab = onProfile && (
      (document.body && document.body.classList && document.body.classList.contains('profile-posts-mode')) ||
      /(?:^|[?&])tab=posts(?:&|$)/.test(search)
    );
    var onFeed = /feed\.php/.test(path) || surface === 'feed';
    var onReel = /reel\.php/.test(path) || surface === 'reel';
    var onPublic = /public\.php/.test(path) || (surface === 'public' && !onReel && !onFeed);

    if(onPostsTab){
      return 'Only you will see it. It will leave your Posts tab and move to your Gallery → Private.';
    }
    if(onFeed){
      return 'Only you will see it. It will leave Friends and move to your Gallery → Private.';
    }
    if(onPublic || onReel){
      return 'Only you will see it. It will leave Discover and Reels and move to your Gallery → Private.';
    }
    if(onProfile){
      return 'Only you will see it. It will move to your Gallery → Private.';
    }
    return 'Only you will see it. It will move to your Gallery → Private.';
  }

  function openPcmPrivateConfirm(postId, onConfirm){
    postId = Number(postId || 0);
    if(!postId) return false;
    var dialog = document.getElementById('pcmPrivateConfirmDialog');
    if(!dialog) return false;
    var input = document.getElementById('pcmPrivatePostId');
    if(input) input.value = String(postId);
    var body = document.getElementById('pcmPrivateConfirmBody');
    if(body) body.textContent = privateConfirmBodyText();
    window.__pcmPendingPrivateId = postId;
    window.__pcmPendingPrivateDone = typeof onConfirm === 'function' ? onConfirm : null;
    showModal('pcmPrivateConfirmDialog');
    return true;
  }

  function closePcmPrivateConfirm(){
    hideModal('pcmPrivateConfirmDialog');
    window.__pcmPendingPrivateId = 0;
    window.__pcmPendingPrivateDone = null;
    var input = document.getElementById('pcmPrivatePostId');
    if(input) input.value = '0';
  }

  function runSetVisibility(postId, visibility, done){
    postId = Number(postId || 0);
    visibility = normalizeVisibility(visibility || 'private');
    if(!postId){
      if(typeof done === 'function') done({ok:false, error:'Missing post id'});
      return;
    }
    var api = String(opts.api_url || 'feed_api.php');
    var body = new URLSearchParams({
      ajax: 'set_visibility',
      post_id: String(postId),
      visibility: visibility
    });
    fetch(api, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body,
      credentials: 'same-origin'
    }).then(function(r){ return r.json(); }).then(function(res){
      if(res && res.ok !== false){
        var nextVis = normalizeVisibility(res.visibility || visibility);
        syncVisibilityOnCards(postId, nextVis);
        if(nextVis === 'private' && shouldRemoveAfterPrivate()){
          removePostFromSurfaces(postId);
        }
        pcmToast(String(res.message || (nextVis === 'private'
          ? 'Moved to Private. Find it in Gallery → Private.'
          : 'Visibility updated.')));
        var redirect = String(res.redirect || '').trim();
        if(nextVis === 'private' && redirect && shouldRemoveAfterPrivate()){
          // Stay on surface after hide; optional soft navigate only if user is alone on empty feed.
        }
      } else {
        pcmToast((res && res.error) ? String(res.error) : 'Could not update visibility.');
      }
      if(typeof done === 'function') done(res);
    }).catch(function(){
      pcmToast('Network error while updating visibility.');
      if(typeof done === 'function') done(null);
    });
  }

  function confirmMakePrivate(btn){
    if(!btn) return;
    var postId = Number(
      btn.getAttribute('data-post-id') ||
      (activePortalWrap && activePortalWrap.getAttribute('data-post-id')) ||
      0
    );
    if(!postId) return;
    if(openPcmPrivateConfirm(postId, function(){
      runSetVisibility(postId, 'private');
    })) return;
    if(!window.confirm(privateConfirmBodyText())) return;
    runSetVisibility(postId, 'private');
  }

  function pruneIdList(list, postId){
    if(!Array.isArray(list)) return;
    postId = Number(postId || 0);
    for(var i = list.length - 1; i >= 0; i--){
      if(Number(list[i] || 0) === postId) list.splice(i, 1);
    }
  }

  function closePostViewerIfShowing(postId){
    postId = Number(postId || 0);
    if(!postId) return;
    try{
      if(typeof window.MSBClosePostViewer === 'function'){
        window.MSBClosePostViewer(postId);
        return;
      }
    }catch(eHook){}
    var ov = document.getElementById('pvOverlay');
    if(!ov || !(ov.classList.contains('show') || ov.getAttribute('aria-hidden') === 'false')) return;
    var wrap = document.getElementById('pvMenuWrap');
    var showing = Number((wrap && wrap.getAttribute('data-post-id')) || 0);
    if(showing !== postId) return;
    if(typeof window.pvClose === 'function'){
      try{ window.pvClose(); }catch(eClose){}
    }else{
      try{
        ov.classList.remove('show');
        ov.setAttribute('aria-hidden', 'true');
      }catch(eOv){}
    }
    try{
      var u = new URL(window.location.href);
      var changed = false;
      ['post', 'post_id', 'open_post', 'fresh'].forEach(function(key){
        if(u.searchParams.has(key)){
          u.searchParams.delete(key);
          changed = true;
        }
      });
      if(changed) window.history.replaceState({}, '', u.pathname + (u.search || '') + u.hash);
    }catch(eUrl){}
  }

  function removePostFromSurfaces(postId){
    postId = Number(postId || 0);
    if(!postId) return;
    try { if(typeof window.MSBFeedRemoveDeletedPost === 'function') window.MSBFeedRemoveDeletedPost(postId); } catch(e0){}
    if(typeof window.MSBReelAfterPostDeleted === 'function'){
      try { window.MSBReelAfterPostDeleted(postId); } catch(eReel){}
    }
    closePostViewerIfShowing(postId);
    try{
      pruneIdList(window.GRID_IDS, postId);
      pruneIdList(window.GALLERY_GRID_IDS, postId);
      pruneIdList(window.TAGS_GRID_IDS, postId);
      pruneIdList(window.pvActiveGridIds, postId);
    }catch(eIds){}
    var removedProfileCard = false;
    document.querySelectorAll(
      '.mf-card[data-id="'+String(postId)+'"],' +
      '.mf-card[data-post-id="'+String(postId)+'"],' +
      '.public-post-card[data-post-id="'+String(postId)+'"],' +
      '.public-post-card[data-id="'+String(postId)+'"],' +
      '.ig-item[data-post-id="'+String(postId)+'"]'
    ).forEach(function(el){
      try {
        if(el.closest && el.closest('#profilePostsFeed')) removedProfileCard = true;
        el.remove();
      } catch(e){}
    });
    if(window.TTStories && typeof window.TTStories.removePost === 'function'){
      try { window.TTStories.removePost(postId); } catch(e2){}
    }
    if(typeof window.MSBProfileAfterPostDeleted === 'function' && (removedProfileCard || document.body.classList.contains('profile-page'))){
      try { window.MSBProfileAfterPostDeleted(postId); } catch(eProfile){}
    }
  }

  function openPcmDeleteConfirm(postId, done){
    postId = Number(postId || 0);
    if(!postId) return false;
    var dialog = document.getElementById('pcmDeleteConfirmDialog');
    if(!dialog) return false;
    window.__pcmPendingDeleteId = postId;
    window.__pcmPendingDeleteDone = typeof done === 'function' ? done : null;
    try{ dialog.setAttribute('data-pcm-post-id', String(postId)); }catch(eAttr){}
    var confirmBtn = document.getElementById('pcmGenericConfirmDeleteBtn');
    if(confirmBtn){
      try{ confirmBtn.setAttribute('data-post-id', String(postId)); }catch(eBtn){}
    }
    pauseStoryDoorForConfirm();
    // Defer so the fries-menu click cannot light-dismiss the confirm popup.
    setTimeout(function(){
      if(Number(window.__pcmPendingDeleteId || 0) !== postId) return;
      try{
        if(dialog.parentNode !== document.body) document.body.appendChild(dialog);
        if(typeof dialog.showModal === 'function'){
          if(!dialog.open) dialog.showModal();
        }else{
          dialog.setAttribute('open', '');
        }
        try{ if(confirmBtn) confirmBtn.focus(); }catch(eFocus){}
      }catch(eOpen){
        try{ dialog.setAttribute('open', ''); }catch(e2){}
      }
    }, 0);
    return true;
  }

  function pauseStoryDoorForConfirm(){
    try{
      var wrap = document.getElementById('tt-stories-wrap');
      if(wrap && wrap.classList && wrap.classList.contains('is-open')
        && window.TTStories && typeof window.TTStories.pause === 'function'){
        window.TTStories.pause();
      }
    }catch(ePause){}
  }

  function openPcmArchiveConfirm(postId, storyHide, onConfirm){
    postId = Number(postId || 0);
    if(!postId) return false;
    var dialog = document.getElementById('pcmArchiveConfirmDialog');
    if(!dialog) return false;
    var titleEl = document.getElementById('pcmArchiveConfirmTitle');
    var bodyEl = document.getElementById('pcmArchiveConfirmBody');
    if(storyHide){
      if(titleEl) titleEl.textContent = 'Archive this story?';
      if(bodyEl) bodyEl.textContent = 'It will leave your story circle and appear at the top of Archive under Stories.';
      pauseStoryDoorForConfirm();
    } else {
      if(titleEl) titleEl.textContent = 'Archive this post?';
      if(bodyEl) bodyEl.textContent = 'It will be hidden from feeds. You can find it later under Posts in Settings → Archived posts.';
    }
    window.__pcmPendingArchiveId = postId;
    window.__pcmPendingArchiveStory = !!storyHide;
    window.__pcmPendingArchiveConfirm = typeof onConfirm === 'function' ? onConfirm : null;
    setTimeout(function(){
      if(Number(window.__pcmPendingArchiveId || 0) !== postId) return;
      try{
        if(dialog.parentNode !== document.body) document.body.appendChild(dialog);
        if(typeof dialog.showModal === 'function'){
          if(!dialog.open) dialog.showModal();
        }else{
          dialog.setAttribute('open', '');
        }
        var confirmBtn = document.getElementById('pcmGenericConfirmArchiveBtn');
        try{ if(confirmBtn) confirmBtn.focus(); }catch(eFocus){}
      }catch(eOpen){
        try{ dialog.setAttribute('open', ''); }catch(e2){}
      }
    }, 0);
    return true;
  }

  function closePcmArchiveConfirm(){
    window.__pcmPendingArchiveId = 0;
    window.__pcmPendingArchiveStory = false;
    window.__pcmPendingArchiveConfirm = null;
    hideModal('pcmArchiveConfirmDialog');
  }

  function syncArchiveMenuState(postId, archived){
    postId = Number(postId || 0);
    archived = !!archived;
    document.querySelectorAll('.pcm-archive[data-post-id="'+String(postId)+'"]').forEach(function(btn){
      btn.setAttribute('data-archived', archived ? '1' : '0');
      var label = btn.querySelector('span');
      if(label) label.textContent = archived ? 'Unarchive' : 'Archive';
    });
    document.querySelectorAll('.mf-card[data-id="'+String(postId)+'"], .public-post-card[data-post-id="'+String(postId)+'"]').forEach(function(card){
      card.setAttribute('data-is-archived', archived ? '1' : '0');
    });
  }

  function isStoryHideContext(btn){
    if(btn && String(btn.getAttribute('data-story-hide') || '') === '1') return true;
    if(btn && btn.closest){
      if(btn.closest('#ttStoriesMenuWrap, #ttStoriesMenu, .tt-stories-menu-wrap')) return true;
      var portal = btn.closest('.pcm-menu-portal');
      if(portal && String(portal.getAttribute('data-story-hide') || '') === '1') return true;
    }
    if(activePortalWrap){
      if(activePortalWrap.id === 'ttStoriesMenuWrap') return true;
      if(activePortalWrap.classList && activePortalWrap.classList.contains('tt-stories-menu-wrap')) return true;
    }
    return false;
  }

  function runArchive(postId, archived, done, opts){
    postId = Number(postId || 0);
    if(!postId) return;
    opts = opts || {};
    // Only story-door Archive uses from_story. Feed/public card fries → Posts list.
    var storyHide = !!opts.fromStory;
    var url = trackApiUrl();
    var body = new URLSearchParams({
      ajax: 'archive',
      post_id: String(postId),
      archived: archived ? '1' : '0',
      from_story: (storyHide && archived) ? '1' : '0'
    });
    fetch(url, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      credentials:'same-origin',
      body: body
    })
      .then(function(r){ return r.json(); })
      .then(function(res){
        if(res && res.ok !== false){
          var nextArchived = Number(
            res.archived != null ? res.archived :
            (res.state && res.state.archived != null ? res.state.archived : (archived ? 1 : 0))
          ) === 1;
          syncArchiveMenuState(postId, nextArchived);
          if(nextArchived){
            removePostFromSurfaces(postId);
            if(typeof window.MSBFeedRebuildStories === 'function'){
              try{ window.MSBFeedRebuildStories(); }catch(eRebuild){}
            }
            if(storyHide && window.TTStories){
              try{
                if(typeof window.TTStories.close === 'function') window.TTStories.close();
                else if(typeof window.TTStories.closeAll === 'function') window.TTStories.closeAll();
              }catch(eClose){}
            }
            pcmToast(storyHide
              ? 'Story archived. Open Archive to see it in your story circle.'
              : 'Post archived. Find it under Posts in Settings → Archived posts.');
          } else {
            pcmToast(storyHide ? 'Story restored to your feed.' : 'Post unarchived.');
          }
        } else {
          pcmToast((res && res.error) ? String(res.error) : 'Could not archive this post.');
        }
        if(typeof done === 'function') done(res);
      })
      .catch(function(){
        pcmToast('Network error while archiving.');
        if(typeof done === 'function') done(null);
      });
  }

  function confirmArchive(btn, fromStoryOverride){
    if(!btn) return;
    var postId = Number(btn.getAttribute('data-post-id') || 0);
    if(!postId) return;
    var isArchived = String(btn.getAttribute('data-archived') || '0') === '1';
    var nextArchived = !isArchived;
    var storyHide = (typeof fromStoryOverride === 'boolean')
      ? fromStoryOverride
      : isStoryHideContext(btn);
    if(nextArchived){
      if(openPcmArchiveConfirm(postId, storyHide, function(){
        runArchive(postId, true, null, { fromStory: storyHide });
      })) return;
      var msg = storyHide
        ? 'Archive this story? It will leave your story circle and appear at the top of Archive under Stories.'
        : 'Archive this post? It will be hidden from feeds. You can find it later under Posts in Settings → Archived posts.';
      if(!window.confirm(msg)) return;
    }
    runArchive(postId, nextArchived, null, { fromStory: storyHide });
  }

  function confirmDelete(postId, done){
    postId = Number(postId || 0);
    if(!postId) return;
    var mode = String(opts.delete_mode || 'confirm');
    if(String(opts.confirm_handler || '') === 'reel'
      && window.MSBReelDeleteConfirm
      && typeof window.MSBReelDeleteConfirm.open === 'function'){
      // Defer so the fries-menu click cannot light-dismiss the confirm popup.
      var reelDeleteId = postId;
      var reelDeleteDone = done;
      setTimeout(function(){
        if(window.MSBReelDeleteConfirm && typeof window.MSBReelDeleteConfirm.open === 'function'){
          window.MSBReelDeleteConfirm.open(reelDeleteId, reelDeleteDone);
        }
      }, 0);
      return;
    }
    if(String(opts.confirm_handler || '') === 'feed'
      && window.MSBFeedDeleteConfirm
      && typeof window.MSBFeedDeleteConfirm.open === 'function'){
      // Defer open so the fries-menu click cannot light-dismiss the confirm popup.
      var feedDeleteId = postId;
      var feedDeleteDone = done;
      setTimeout(function(){
        if(window.MSBFeedDeleteConfirm && typeof window.MSBFeedDeleteConfirm.open === 'function'){
          window.MSBFeedDeleteConfirm.open(feedDeleteId, feedDeleteDone);
        }
      }, 0);
      return;
    }
    // Shared popup for profile / public (and fallback).
    if(openPcmDeleteConfirm(postId, done)) return;
    if(mode === 'public' && document.getElementById('deleteConfirmModal')){
      window.__pcmPendingDeleteId = postId;
      showModal('deleteConfirmModal');
      if(typeof done === 'function') done();
      return;
    }
    if(mode === 'profile' && document.getElementById('profileDeleteConfirmModal')){
      window.__pcmPendingDeleteId = postId;
      showModal('profileDeleteConfirmModal');
      if(typeof done === 'function') done();
      return;
    }
    if(!window.confirm('Delete this post?')) return;
    runDelete(postId, done);
  }

  function runDelete(postId, done){
    postId = Number(postId || 0);
    if(!postId){
      if(typeof done === 'function') done({ok:false, error:'Missing post id'});
      return;
    }
    var mode = String(opts.delete_mode || 'feed');
    function onOk(res){
      // Confirm UI usually removes the card first; keep this as a safe fallback.
      if(res && res.ok !== false){
        removePostFromSurfaces(postId);
      }
      if(typeof done === 'function') done(res || {ok:false});
    }
    function onFail(){
      if(typeof done === 'function') done({ok:false, error:'network'});
    }
    if(mode === 'feed' && opts.api_url){
      // Card already removed by confirm UI; persist soft-delete in the background.
      if($){
        $.post(opts.api_url, { ajax:'delete_post', post_id: postId }, function(res){
          onOk(res);
        }, 'json').fail(onFail);
        return;
      }
      var body = new URLSearchParams({ ajax:'delete_post', post_id: String(postId) });
      fetch(opts.api_url, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(onOk)
        .catch(onFail);
      return;
    }
    if(mode === 'profile' && typeof window.mfPost === 'function'){
      window.mfPost('delete_post', { post_id: postId }, function(res){
        if(res && res.ok){
          document.querySelectorAll('#profilePostsFeed .mf-card[data-id="'+String(postId)+'"]').forEach(function(el){ el.remove(); });
          removePostFromSurfaces(postId);
        }
        if(typeof done === 'function') done(res || {ok:false});
      });
      return;
    }
    if(mode === 'public'){
      var input = document.getElementById('deletePostId');
      var form = document.getElementById('deletePostForm');
      if(input) input.value = String(postId);
      if(form) form.submit();
      if(typeof done === 'function') done({ok:true});
      return;
    }
    if(typeof done === 'function') done({ok:false});
  }

  function closePcmViewPostOverlay(){
    var ov = document.getElementById('pcmViewPostOverlay');
    var frame = document.getElementById('pcmViewPostFrame');
    if(ov){
      ov.classList.remove('is-open');
      ov.setAttribute('aria-hidden', 'true');
      ov.hidden = true;
    }
    if(frame){
      try{ frame.src = 'about:blank'; }catch(e){}
    }
    try{ document.body.classList.remove('pcm-view-post-open'); }catch(e2){}
  }

  function openPcmViewPostOverlay(postId){
    postId = Number(postId || 0);
    if(postId <= 0) return false;
    var ov = document.getElementById('pcmViewPostOverlay');
    var frame = document.getElementById('pcmViewPostFrame');
    if(!ov || !frame){
      window.location.href = 'post.php?id=' + encodeURIComponent(String(postId));
      return true;
    }
    frame.src = 'post.php?id=' + encodeURIComponent(String(postId));
    ov.hidden = false;
    ov.classList.add('is-open');
    ov.setAttribute('aria-hidden', 'false');
    try{ document.body.classList.add('pcm-view-post-open'); }catch(e){}
    return true;
  }

  function openViewThePost(postId, opts){
    postId = Number(postId || 0);
    if(postId <= 0) return false;
    opts = (opts && typeof opts === 'object') ? opts : {};
    closeMenus();
    // Prefer the gallery-style #pvOverlay used on feed + profile Posts/Gallery.
    if(typeof window.pvOpenById === 'function'){
      try{
        var opened = window.pvOpenById(postId, opts);
        if(opened === true) return true;
        var ov = document.getElementById('pvOverlay');
        if(ov && ov.classList.contains('show')) return true;
      }catch(eOpen){}
    }
    return openPcmViewPostOverlay(postId);
  }

  function handleMenuItemAction(target, e){
    if(!target) return false;
    e = e || {};

    var viewPostBtn = closest(target, menuItemSel('.pcm-view-post'));
    if(viewPostBtn){
      if(e.preventDefault) e.preventDefault();
      if(e.stopPropagation) e.stopPropagation();
      var viewPostId = Number(
        viewPostBtn.getAttribute('data-post-id') ||
        (activePortalWrap && activePortalWrap.getAttribute('data-post-id')) ||
        0
      );
      if(!viewPostId){
        var viewCard = closest(viewPostBtn, '.mf-card, .public-post-card, [data-post-id], [data-id]');
        viewPostId = Number((viewCard && (viewCard.getAttribute('data-post-id') || viewCard.getAttribute('data-id'))) || 0);
      }
      openViewThePost(viewPostId);
      return true;
    }

    var editLink = closest(target, menuItemSel('.pcm-edit'));
    if(editLink){
      if(e.preventDefault) e.preventDefault();
      if(e.stopPropagation) e.stopPropagation();
      closeMenus();
      var href = String(editLink.getAttribute('href') || '').trim();
      if(!href || !/[?&]edit=\d+/i.test(href)){
        var card = closest(editLink, '.public-post-card, .mf-card, [data-edit-url], [data-post-id]');
        var fromCard = card ? String(card.getAttribute('data-edit-url') || '').trim() : '';
        if(fromCard) href = fromCard;
        if((!href || !/[?&]edit=\d+/i.test(href)) && card){
          var pid = Number(card.getAttribute('data-post-id') || card.getAttribute('data-id') || 0);
          if(pid > 0) href = 'dashboard.php?modal=1&edit=' + String(pid);
        }
      }
      if(!href) return true;
      if(window.MSBCreatePostModal && typeof window.MSBCreatePostModal.open === 'function'){
        window.MSBCreatePostModal.open(href);
      } else {
        window.location.href = href;
      }
      return true;
    }

    var navLink = closest(target, menuItemSel('.pcm-view, .pcm-friends, .pcm-message, .pcm-edit-contact, .pcm-timeline'));
    if(navLink){
      if(e.preventDefault) e.preventDefault();
      if(e.stopPropagation) e.stopPropagation();
      followMenuLink(navLink);
      return true;
    }

    var delBtn = closest(target, menuItemSel('.pcm-delete'));
    if(delBtn){
      if(e.preventDefault) e.preventDefault();
      if(e.stopPropagation) e.stopPropagation();
      if(e.stopImmediatePropagation) e.stopImmediatePropagation();
      var card = closest(delBtn, '.mf-card, .public-post-card, [data-post-id], [data-id]');
      var deletePostId = Number(
        delBtn.getAttribute('data-post-id') ||
        (activePortalWrap && activePortalWrap.getAttribute('data-post-id')) ||
        (card && (card.getAttribute('data-post-id') || card.getAttribute('data-id'))) ||
        0
      );
      closeMenus();
      if(deletePostId > 0) confirmDelete(deletePostId);
      return true;
    }

    var archiveBtn = closest(target, menuItemSel('.pcm-archive'));
    if(archiveBtn){
      if(e.preventDefault) e.preventDefault();
      if(e.stopPropagation) e.stopPropagation();
      // Capture story-door context before the portal is torn down.
      var fromStoryDoor = isStoryHideContext(archiveBtn);
      closeMenus();
      confirmArchive(archiveBtn, fromStoryDoor);
      return true;
    }

    var privateBtn = closest(target, menuItemSel('.pcm-private'));
    if(privateBtn){
      if(e.preventDefault) e.preventDefault();
      if(e.stopPropagation) e.stopPropagation();
      closeMenus();
      confirmMakePrivate(privateBtn);
      return true;
    }

    var tagBtn = closest(target, menuItemSel('.pcm-tag'));
    if(tagBtn){
      if(e.preventDefault) e.preventDefault();
      if(e.stopPropagation) e.stopPropagation();
      var tagFromStory = isStoryHideContext(tagBtn);
      var tagPid = Number(
        tagBtn.getAttribute('data-post-id') ||
        (activePortalWrap && activePortalWrap.getAttribute('data-post-id')) ||
        0
      );
      if(tagFromStory){
        window.__pcmTagFromStory = true;
        pauseStoryDoorForMenu();
      }
      closeMenus();
      if(!tagPid) return true;
      openPcmTagSheet(tagPid);
      return true;
    }

    var mentionBtn = closest(target, menuItemSel('.pcm-mention'));
    if(mentionBtn){
      if(e.preventDefault) e.preventDefault();
      if(e.stopPropagation) e.stopPropagation();
      var mentionFromStory = isStoryHideContext(mentionBtn);
      var mentionPid = Number(
        mentionBtn.getAttribute('data-post-id') ||
        (activePortalWrap && activePortalWrap.getAttribute('data-post-id')) ||
        0
      );
      if(mentionFromStory){
        window.__pcmMentionFromStory = true;
        pauseStoryDoorForMenu();
      }
      closeMenus();
      if(!mentionPid) return true;
      openPcmMentionSheet(mentionPid);
      return true;
    }

    var tagSelfBtn = closest(target, menuItemSel('.pcm-tag-self'));
    if(tagSelfBtn){
      if(e.preventDefault) e.preventDefault();
      if(e.stopPropagation) e.stopPropagation();
      var selfPid = Number(
        tagSelfBtn.getAttribute('data-post-id') ||
        (activePortalWrap && activePortalWrap.getAttribute('data-post-id')) ||
        0
      );
      closeMenus();
      if(!selfPid) return true;
      toggleSelfTagOnPost(selfPid, tagSelfBtn);
      return true;
    }

    var unfollowBtn = closest(target, menuItemSel('.pcm-follow, .pcm-unfollow'));
    if(unfollowBtn){
      if(e.preventDefault) e.preventDefault();
      if(e.stopPropagation) e.stopPropagation();
      closeMenus();
      var pubId = Number(unfollowBtn.getAttribute('data-publisher-id') || 0);
      if(!pubId) return true;
      var fd = new FormData();
      fd.append('target_id', String(pubId));
      fetch('publisher_follow_toggle.php', { method:'POST', body: fd, cache:'no-store' })
        .then(function(r){ return r.json(); })
        .then(function(res){
          if(!res || !res.ok) return;
          if(typeof window.mfSyncPublisherUiForPub === 'function'){
            window.mfSyncPublisherUiForPub(pubId, !!res.following);
          } else if(typeof window.applyFollowForPublisher === 'function'){
            window.applyFollowForPublisher(pubId, !!res.following);
          }
        });
      return true;
    }

    var addFriendBtn = closest(target, menuItemSel('.pcm-add-friend'));
    if(addFriendBtn){
      if(e.preventDefault) e.preventDefault();
      if(e.stopPropagation) e.stopPropagation();
      closeMenus();
      var peerId = Number(addFriendBtn.getAttribute('data-peer-id') || 0);
      if(!peerId) return true;
      var body = new URLSearchParams({ action: 'send', peer_id: String(peerId) });
      fetch('ajax/friend_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body
      }).then(function(r){ return r.json(); }).then(function(res){
        if(!res || !res.status) return;
        if(typeof window.mfSyncFriendUiForPeer === 'function'){
          window.mfSyncFriendUiForPeer(peerId, String(res.status));
        } else if(typeof window.applyStatusForPeer === 'function'){
          window.applyStatusForPeer(peerId, String(res.status));
        } else {
          syncFriendCards(peerId, String(res.status));
          if(typeof window.msbApplyFriendActionBtnState === 'function'){
            document.querySelectorAll('.friend-btn[data-peer-id="'+String(peerId)+'"]').forEach(function(btn){
              window.msbApplyFriendActionBtnState(btn, String(res.status));
            });
          }
        }
      });
      return true;
    }

    var unfriendBtn = closest(target, menuItemSel('.pcm-unfriend'));
    if(unfriendBtn){
      if(e.preventDefault) e.preventDefault();
      if(e.stopPropagation) e.stopPropagation();
      closeMenus();
      var unfriendPeerId = Number(unfriendBtn.getAttribute('data-peer-id') || 0);
      if(!unfriendPeerId) return true;
      var unfriendBody = new URLSearchParams({ action: 'unfriend', peer_id: String(unfriendPeerId) });
      fetch('ajax/friend_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: unfriendBody
      }).then(function(r){ return r.json(); }).then(function(res){
        if(!res || !res.ok){
          if(res && res.message) pcmToast(String(res.message));
          return;
        }
        var nextStatus = String(res.status || 'none');
        if(typeof window.mfSyncFriendUiForPeer === 'function'){
          window.mfSyncFriendUiForPeer(unfriendPeerId, nextStatus);
        } else if(typeof window.applyStatusForPeer === 'function'){
          window.applyStatusForPeer(unfriendPeerId, nextStatus);
        } else {
          syncFriendCards(unfriendPeerId, nextStatus);
        }
      });
      return true;
    }

    var renameBtn = closest(target, menuItemSel('.pcm-rename'));
    if(renameBtn){
      if(e.preventDefault) e.preventDefault();
      if(e.stopPropagation) e.stopPropagation();
      closeMenus();
      var id = Number(renameBtn.getAttribute('data-contact-id') || 0);
      var name = String(renameBtn.getAttribute('data-rename-name') || '');
      if(!id) return true;
      var idEl = document.getElementById('pcmRenameId');
      var inputEl = document.getElementById('pcmRenameInput');
      var errEl = document.getElementById('pcmRenameErr');
      if(idEl) idEl.value = String(id);
      if(inputEl) inputEl.value = name;
      if(errEl){ errEl.style.display = 'none'; errEl.textContent = ''; }
      showModal('pcmRenameModal');
      setTimeout(function(){ if(inputEl) inputEl.focus(); }, 250);
      return true;
    }

    var undoBtn = closest(target, menuItemSel('.pcm-undo-rename'));
    if(undoBtn){
      if(e.preventDefault) e.preventDefault();
      if(e.stopPropagation) e.stopPropagation();
      closeMenus();
      var undoId = Number(undoBtn.getAttribute('data-contact-id') || 0);
      if(!undoId) return true;
      fetch('ajax/contact_undo_rename.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body: new URLSearchParams({ contact_id: String(undoId) })
      }).then(function(r){ return r.json(); }).then(function(data){
        if(!data || !data.ok) throw new Error((data && data.error) || 'Nothing to undo.');
        var label = String(data.display_name || '');
        document.querySelectorAll('.pcm-rename[data-contact-id="'+String(undoId)+'"]').forEach(function(btn){
          btn.setAttribute('data-rename-name', label);
        });
        if(typeof window.msbSyncContactDisplayName === 'function'){
          window.msbSyncContactDisplayName(undoId, label);
        }
      }).catch(function(ex){
        window.alert((ex && ex.message) ? ex.message : 'Nothing to undo.');
      });
      return true;
    }

    var reportBtn = closest(target, menuItemSel('.pcm-report'));
    if(reportBtn){
      if(e.preventDefault) e.preventDefault();
      if(e.stopPropagation) e.stopPropagation();
      closeMenus();
      pcmToast('Thanks for your report.');
      return true;
    }

    var bookmarkBtn = closest(target, menuItemSel('.pcm-bookmark'));
    if(bookmarkBtn){
      if(e.preventDefault) e.preventDefault();
      if(e.stopPropagation) e.stopPropagation();
      var fromStory = isStoryHideContext(bookmarkBtn);
      var bookmarkPid = Number(
        bookmarkBtn.getAttribute('data-post-id') ||
        (activePortalWrap && activePortalWrap.getAttribute('data-post-id')) ||
        0
      );
      closeMenus();
      if(!bookmarkPid) return true;
      postTrack('save', bookmarkPid, function(res){
        if(!res || res.ok === false){
          pcmToast((res && res.error) ? String(res.error) : 'Could not update bookmark.');
          return;
        }
        var saved = Number(res.state && res.state.saved != null ? res.state.saved : 0) === 1;
        pcmToast(saved
          ? 'Saved to Bookmarks. Find it in Settings → Bookmarks.'
          : 'Removed from Bookmarks.');
      }, { fromStory: fromStory });
      return true;
    }

    var shareBtn = closest(target, menuItemSel('.pcm-share'));
    if(shareBtn){
      if(e.preventDefault) e.preventDefault();
      if(e.stopPropagation) e.stopPropagation();
      var sharePid = Number(
        shareBtn.getAttribute('data-post-id') ||
        (activePortalWrap && activePortalWrap.getAttribute('data-post-id')) ||
        0
      );
      var shareWrap = closest(shareBtn, '.post-card-menu-wrap, .mf-menu-wrap') || activePortalWrap;
      closeMenus();
      if(!sharePid) return true;
      if(!openPcmShareSheet(sharePid, shareWrap)){
        copyText(absolutePostUrl(sharePid, shareWrap)).then(function(){
          pcmToast('Link copied to clipboard.');
        });
        recordShareOnce(sharePid);
      }
      return true;
    }

    var postBtn = closest(target, menuItemSel('.pcm-post'));
    if(postBtn){
      if(e.preventDefault) e.preventDefault();
      if(e.stopPropagation) e.stopPropagation();
      var postPid = Number(
        postBtn.getAttribute('data-post-id') ||
        (activePortalWrap && activePortalWrap.getAttribute('data-post-id')) ||
        0
      );
      closeMenus();
      if(!postPid) return true;
      openPcmPostDestDialog(postPid);
      return true;
    }

    var copyBtn = closest(target, menuItemSel('.pcm-copy-link'));
    if(copyBtn){
      if(e.preventDefault) e.preventDefault();
      if(e.stopPropagation) e.stopPropagation();
      var copyPid = Number(
        copyBtn.getAttribute('data-post-id') ||
        (activePortalWrap && activePortalWrap.getAttribute('data-post-id')) ||
        0
      );
      var copyWrap = closest(copyBtn, '.post-card-menu-wrap, .mf-menu-wrap') || activePortalWrap;
      closeMenus();
      if(!copyPid) return true;
      copyText(absolutePostUrl(copyPid, copyWrap)).then(function(){
        pcmToast('Post link copied. Paste it anywhere to open this post.');
      }).catch(function(){
        pcmToast('Could not copy link.');
      });
      return true;
    }

    return false;
  }

  function onDocumentClick(e){
    var target = e.target;
    if(target && target.nodeType === 3) target = target.parentElement;
    if(!target) return;

    var othersBtn = closest(target, '.msb-sharing-others-btn');
    if (othersBtn) {
      e.preventDefault();
      e.stopPropagation();
      toggleSharingOthersMenu(othersBtn);
      return;
    }
    if (!closest(target, '.msb-sharing-others-wrap')) {
      closeSharingOthersMenus();
    }

    // Feed has several delegated click handlers. Handle the native dialog's
    // destructive action in this capture-phase dispatcher so a later handler
    // cannot stop the confirmation click before the delete request is sent.
    if(closest(target, '#pcmGenericConfirmDeleteBtn')){
      e.preventDefault();
      e.stopPropagation();
      if(e.stopImmediatePropagation) e.stopImmediatePropagation();
      onGenericConfirmDeleteClick(target);
      return;
    }
    if(closest(target, '#pcmGenericConfirmArchiveBtn')){
      e.preventDefault();
      e.stopPropagation();
      if(e.stopImmediatePropagation) e.stopImmediatePropagation();
      onGenericConfirmArchiveClick();
      return;
    }
    if(closest(target, '#feedDeleteDialogConfirm')){
      e.preventDefault();
      e.stopPropagation();
      if(e.stopImmediatePropagation) e.stopImmediatePropagation();
      if(window.MSBFeedDeleteConfirm && typeof window.MSBFeedDeleteConfirm.confirm === 'function'){
        window.MSBFeedDeleteConfirm.confirm();
      }
      return;
    }
    if(closest(target, '#reelDeleteDialogConfirm')){
      e.preventDefault();
      e.stopPropagation();
      if(e.stopImmediatePropagation) e.stopImmediatePropagation();
      if(window.MSBReelDeleteConfirm && typeof window.MSBReelDeleteConfirm.confirm === 'function'){
        window.MSBReelDeleteConfirm.confirm();
      }
      return;
    }
    // Keep the confirm popup open — do not treat dialog clicks as outside-menu dismiss.
    if(closest(target, '#feedDeleteDialog, #pcmDeleteConfirmDialog, #pcmArchiveConfirmDialog, #pcmShareSheet, #pcmTagSheet, #reelDeleteDialog')){
      return;
    }

    var menuBtn = closest(target, '.post-card-menu-btn');
    if(menuBtn){
      e.preventDefault();
      e.stopPropagation();
      if(e.stopImmediatePropagation) e.stopImmediatePropagation();
      toggleMenuBtn(menuBtn);
      return;
    }

    if(handleMenuItemAction(target, e)) return;

    if(closest(target, '.post-card-menu-wrap, .mf-menu-wrap.post-card-menu-wrap, .' + PORTAL_CLASS)) return;
    closeMenus();
  }

  function repositionPortals(){
    if(!activePortal || !activePortalWrap) return;
    var btn = activePortalWrap.querySelector('.post-card-menu-btn');
    if(btn) positionPortal(btn, activePortal);
  }

  function onConfirmDeleteClick(){
    var delInput = document.getElementById('deletePostId');
    var postId = Number(window.__pcmPendingDeleteId || (delInput ? delInput.value : 0) || 0);
    if(!postId) return;
    runDelete(postId, function(){
      window.__pcmPendingDeleteId = 0;
      hideModal('deleteConfirmModal');
    });
  }

  function onProfileConfirmDeleteClick(){
    var postId = Number(window.__pcmPendingDeleteId || 0);
    if(!postId) return;
    window.__pcmPendingDeleteId = 0;
    // Remove card + close popup immediately; API soft-delete runs in background.
    removePostFromSurfaces(postId);
    hideModal('profileDeleteConfirmModal');
    runDelete(postId, function(res){
      if(res && res.ok !== false) return;
      var msg = (res && res.error) ? String(res.error) : 'Could not delete this post.';
      try { window.alert(msg); } catch(eAlert){}
      try { window.location.reload(); } catch(eReload){}
    });
  }

  function onGenericConfirmDeleteClick(fromEl){
    var dialog = document.getElementById('pcmDeleteConfirmDialog');
    var btn = fromEl ? closest(fromEl, '#pcmGenericConfirmDeleteBtn') : document.getElementById('pcmGenericConfirmDeleteBtn');
    var postId = Number(
      window.__pcmPendingDeleteId ||
      (btn && btn.getAttribute('data-post-id')) ||
      (dialog && dialog.getAttribute('data-pcm-post-id')) ||
      0
    );
    if(!postId) return;
    var done = window.__pcmPendingDeleteDone;
    window.__pcmPendingDeleteId = 0;
    window.__pcmPendingDeleteDone = null;
    try{ if(dialog) dialog.removeAttribute('data-pcm-post-id'); }catch(eClear){}
    try{ if(btn) btn.removeAttribute('data-post-id'); }catch(eClearBtn){}
    // Close popup + remove surfaces first; API soft-delete runs next.
    try{ hideModal('pcmDeleteConfirmDialog'); }catch(eHide){}
    try{ removePostFromSurfaces(postId); }catch(eRm){}
    runDelete(postId, function(res){
      if(res && res.ok !== false){
        try{
          if(typeof pcmToast === 'function') pcmToast('Post deleted.');
        }catch(eToast){}
        if(typeof done === 'function') done(res);
        return;
      }
      var msg = (res && res.error) ? String(res.error) : 'Could not delete this post.';
      try { window.alert(msg); } catch(eAlert){}
      try { window.location.reload(); } catch(eReload){}
      if(typeof done === 'function') done(res || {ok:false});
    });
  }

  function onGenericConfirmArchiveClick(){
    var postId = Number(window.__pcmPendingArchiveId || 0);
    if(!postId) return;
    var storyHide = !!window.__pcmPendingArchiveStory;
    var onConfirm = window.__pcmPendingArchiveConfirm;
    closePcmArchiveConfirm();
    if(typeof onConfirm === 'function'){
      onConfirm();
      return;
    }
    runArchive(postId, true, null, { fromStory: storyHide });
  }

  function onRenameSaveClick(){
    var idEl = document.getElementById('pcmRenameId');
    var inputEl = document.getElementById('pcmRenameInput');
    var errEl = document.getElementById('pcmRenameErr');
    var id = Number(idEl ? idEl.value : 0);
    var newName = String(inputEl ? inputEl.value : '').trim();
    if(errEl){ errEl.style.display = 'none'; errEl.textContent = ''; }
    if(!id || !newName){
      if(errEl){ errEl.textContent = 'Name is required.'; errEl.style.display = 'block'; }
      return;
    }
    fetch('ajax/contact_rename.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body: new URLSearchParams({ contact_id: String(id), display_name: newName })
    }).then(function(r){ return r.json(); }).then(function(data){
      if(!data || !data.ok) throw new Error((data && data.error) || 'Rename failed.');
      document.querySelectorAll('.pcm-rename[data-contact-id="'+String(id)+'"]').forEach(function(btn){
        btn.setAttribute('data-rename-name', newName);
      });
      if(typeof window.msbSyncContactDisplayName === 'function'){
        window.msbSyncContactDisplayName(id, newName);
      }
      hideModal('pcmRenameModal');
    }).catch(function(ex){
      if(errEl){
        errEl.textContent = (ex && ex.message) ? ex.message : 'Rename failed.';
        errEl.style.display = 'block';
      }
    });
  }

  var pcmContrastCanvas = null;
  var pcmContrastCtx = null;

  function pcmContrastCanvasCtx(){
    if(!pcmContrastCanvas){
      pcmContrastCanvas = document.createElement('canvas');
      pcmContrastCtx = pcmContrastCanvas.getContext('2d', { willReadFrequently: true });
    }
    return pcmContrastCtx;
  }

  function findOnMediaMenuMedia(btn){
    if(!btn) return null;
    var shell = btn.closest('.mf-media-shell, .media-stage, .post.public-post-card');
    if(!shell) return null;
    return shell.querySelector('.mf-media img, .mf-media video, .media-stage > img, .media-stage > video, .media-stage .media-carousel img, .media-stage .media-carousel video');
  }

  function sampleMediaLuminance(mediaEl, btn){
    if(!mediaEl || !btn) return null;
    var ctx = pcmContrastCanvasCtx();
    if(!ctx) return null;

    var rect = btn.getBoundingClientRect();
    var mediaRect = mediaEl.getBoundingClientRect();
    if(!rect.width || !rect.height || !mediaRect.width || !mediaRect.height) return null;

    var naturalW = Number(mediaEl.videoWidth || mediaEl.naturalWidth || 0);
    var naturalH = Number(mediaEl.videoHeight || mediaEl.naturalHeight || 0);
    if(!naturalW || !naturalH) return null;

    var scaleX = naturalW / mediaRect.width;
    var scaleY = naturalH / mediaRect.height;
    var sampleSize = 24;
    var sx = Math.max(0, Math.min(naturalW - sampleSize, Math.round((rect.left + rect.width / 2 - mediaRect.left) * scaleX - sampleSize / 2)));
    var sy = Math.max(0, Math.min(naturalH - sampleSize, Math.round((rect.top + rect.height / 2 - mediaRect.top) * scaleY - sampleSize / 2)));

    pcmContrastCanvas.width = sampleSize;
    pcmContrastCanvas.height = sampleSize;
    try {
      ctx.drawImage(mediaEl, sx, sy, sampleSize, sampleSize, 0, 0, sampleSize, sampleSize);
      var data = ctx.getImageData(0, 0, sampleSize, sampleSize).data;
      var total = 0;
      var count = 0;
      for(var i = 0; i < data.length; i += 4){
        var r = data[i];
        var g = data[i + 1];
        var b = data[i + 2];
        var a = data[i + 3];
        if(a < 16) continue;
        total += (0.2126 * r) + (0.7152 * g) + (0.0722 * b);
        count++;
      }
      if(!count) return null;
      return total / count;
    } catch (err) {
      return null;
    }
  }

  function applyOnMediaMenuContrast(btn){
    if(!btn || !btn.classList || !btn.classList.contains('post-card-menu-btn')) return;
    var head = btn.closest('.mf-head--on-media, .standard-media-topbar');
    if(!head) return;

    btn.classList.remove('pcm-on-dark-media', 'pcm-on-light-media');
    var mediaEl = findOnMediaMenuMedia(btn);
    if(!mediaEl){
      btn.classList.add('pcm-on-dark-media');
      return;
    }

    function measure(){
      var lum = sampleMediaLuminance(mediaEl, btn);
      if(lum == null){
        btn.classList.add('pcm-on-dark-media');
        return;
      }
      if(lum < 128){
        btn.classList.add('pcm-on-dark-media');
      } else {
        btn.classList.add('pcm-on-light-media');
      }
    }

    if(mediaEl.complete === false || (mediaEl.tagName === 'VIDEO' && !mediaEl.videoWidth)){
      mediaEl.addEventListener('load', measure, { once: true });
      mediaEl.addEventListener('loadeddata', measure, { once: true });
      mediaEl.addEventListener('loadedmetadata', measure, { once: true });
      return;
    }
    measure();
  }

  function syncOnMediaMenuContrast(root){
    root = root || document;
    root.querySelectorAll('.mf-head--on-media .post-card-menu-btn, .standard-media-topbar .post-card-menu-btn').forEach(applyOnMediaMenuContrast);
  }

  function observeOnMediaMenuContrast(){
    if(observeOnMediaMenuContrast._bound) return;
    observeOnMediaMenuContrast._bound = true;
    var timer = null;
    function schedule(root){
      if(timer) clearTimeout(timer);
      timer = setTimeout(function(){
        syncOnMediaMenuContrast(root || document);
      }, 80);
    }
    if(typeof MutationObserver === 'function'){
      var obs = new MutationObserver(function(mutations){
        for(var i = 0; i < mutations.length; i++){
          var m = mutations[i];
          if(m.type === 'childList' && m.addedNodes && m.addedNodes.length){
            schedule(document);
            return;
          }
        }
      });
      if(document.body){
        obs.observe(document.body, { childList: true, subtree: true });
      }
    }
    document.addEventListener('load', function(e){
      var t = e.target;
      if(!t || (t.tagName !== 'IMG' && t.tagName !== 'VIDEO')) return;
      var shell = t.closest ? t.closest('.mf-media-shell, .media-stage, .post.public-post-card') : null;
      if(shell) schedule(shell);
    }, true);
    window.addEventListener('resize', function(){ schedule(document); }, { passive: true });
  }

  function isMediaActionCircle(el){
    return !!(el && el.classList && (el.classList.contains('mf-media-action-circle') || el.classList.contains('mf-publisher-follow-circle')));
  }

  function mediaActionCircleHtml(mode){
    if(mode === 'sent' || mode === 'outgoing_pending'){
      return '<span class="mf-media-action-label">Sent</span>';
    }
    if(mode === 'accept' || mode === 'incoming_pending'){
      return '<span class="mf-media-action-label">Accept</span>';
    }
    return '<i class="fa fa-plus" aria-hidden="true"></i>';
  }

  function applyFriendActionBtnState(el, status){
    if(!el) return;
    status = String(status || 'none');
    el.classList.remove('is-friends', 'is-pending', 'is-accept', 'primary');
    el.setAttribute('data-status', status);
    if(isMediaActionCircle(el)){
      if(status === 'friends'){
        var wrap = el.closest ? el.closest('.mf-media-top-actions') : null;
        el.remove();
        if(wrap && !wrap.querySelector('.mf-friend-btn, .publisher-follow-btn, .friend-btn')){
          wrap.remove();
        }
        return;
      }
      if(status === 'incoming_pending'){
        el.innerHTML = mediaActionCircleHtml('accept');
        el.classList.add('is-accept');
        el.disabled = false;
        el.setAttribute('aria-label', 'Accept friend request');
        el.setAttribute('title', 'Accept friend request');
        return;
      }
      if(status === 'outgoing_pending'){
        el.innerHTML = mediaActionCircleHtml('sent');
        el.classList.add('is-pending');
        el.disabled = true;
        el.setAttribute('aria-label', 'Request sent');
        el.setAttribute('title', 'Request sent');
        return;
      }
      el.innerHTML = mediaActionCircleHtml('plus');
      el.classList.add('primary');
      el.disabled = false;
      el.setAttribute('aria-label', 'Add friend');
      el.setAttribute('title', 'Add friend');
      return;
    }
    if(status === 'friends'){
      el.textContent = 'Friends';
      el.classList.add('is-friends');
    }else if(status === 'incoming_pending'){
      el.textContent = 'Accept Friend';
      el.classList.add('is-accept');
    }else if(status === 'outgoing_pending'){
      el.textContent = 'Request Sent';
      el.classList.add('is-pending');
    }else{
      el.textContent = 'Add Friend';
      el.classList.add('primary');
    }
  }

  window.msbApplyFriendActionBtnState = applyFriendActionBtnState;

  function applyPublisherFollowBtnState(el, following){
    if(!el) return;
    following = !!following;
    el.classList.toggle('is-following', following);
    el.classList.toggle('is-pending', following);
    el.classList.toggle('primary', !following);
    if(isMediaActionCircle(el)){
      el.innerHTML = following
        ? '<span class="mf-media-action-label">Sent</span>'
        : '<i class="fa fa-plus" aria-hidden="true"></i>';
      el.setAttribute('aria-label', following ? 'Following' : 'Follow');
      el.setAttribute('title', following ? 'Following' : 'Follow');
      el.disabled = following;
      return;
    }
    el.textContent = following ? 'Following' : 'Follow';
  }

  window.msbApplyPublisherFollowBtnState = applyPublisherFollowBtnState;

  window.MSBPostCardMenu = {
    buildItems: buildItems,
    toggle: toggleMenuBtn,
    refreshFeedCardMenus: refreshFeedCardMenus,
    closeAll: closeMenus,
    confirmDelete: confirmDelete,
    runDelete: runDelete,
    hydrate: hydrateEmptyMenus,
    syncOnMediaContrast: syncOnMediaMenuContrast,
    syncCardPublisher: syncCardPublisher,
    syncPublisherCards: syncPublisherCards,
    syncCardFriend: syncCardFriend,
    syncFriendCards: syncFriendCards,
    syncBookmarkMenuState: syncBookmarkMenuState,
    syncArchiveMenuState: syncArchiveMenuState,
    syncPostTrackState: syncPostTrackState,
    toast: pcmToast,
    openShare: openPcmShareSheet,
    closeShare: closePcmShareSheet,
    openTag: openPcmTagSheet,
    closeTag: closePcmTagSheet,
    openMention: openPcmMentionSheet,
    closeMention: closePcmMentionSheet,
    openViewPost: openViewThePost,
    closeViewPost: closePcmViewPostOverlay,
    openPostDest: openPcmPostDestDialog,
    closePostDest: closePcmPostDestDialog,
    visibilityBadgeHtml: visibilityBadgeHtml,
    authorSharingWithHtml: authorSharingWithHtml,
    displayTextWithoutTagHandles: displayTextWithoutTagHandles,
    textIsPeopleTagOnly: textIsPeopleTagOnly,
    normalizeVisibility: normalizeVisibility,
    setVisibility: runSetVisibility,
    makePrivate: function(postId){ runSetVisibility(Number(postId || 0), 'private'); }
  };

  document.addEventListener('click', onDocumentClick, true);
  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){
      closeSharingOthersMenus();
      closeMenus();
      var genericDeleteModal = document.getElementById('pcmDeleteConfirmDialog');
      if(genericDeleteModal && genericDeleteModal.open){
        hideModal('pcmDeleteConfirmDialog');
      }
      var genericArchiveModal = document.getElementById('pcmArchiveConfirmDialog');
      if(genericArchiveModal && genericArchiveModal.open){
        closePcmArchiveConfirm();
      }
      var shareSheet = document.getElementById('pcmShareSheet');
      if(shareSheet && shareSheet.open){
        closePcmShareSheet();
      }
      var tagSheet = document.getElementById('pcmTagSheet');
      if(tagSheet && tagSheet.open){
        closePcmTagSheet();
      }
      var mentionSheet = document.getElementById('pcmMentionSheet');
      if(mentionSheet && mentionSheet.open){
        closePcmMentionSheet();
      }
      var viewPostOv = document.getElementById('pcmViewPostOverlay');
      if(viewPostOv && viewPostOv.classList.contains('is-open')){
        closePcmViewPostOverlay();
        return;
      }
      var postDest = document.getElementById('pcmPostDestDialog');
      if(postDest && postDest.open){
        closePcmPostDestDialog();
      }
      var privateConfirm = document.getElementById('pcmPrivateConfirmDialog');
      if(privateConfirm && privateConfirm.open){
        closePcmPrivateConfirm();
      }
    }
  });
  window.addEventListener('resize', repositionPortals, {passive:true});
  window.addEventListener('scroll', repositionPortals, {passive:true});
  document.addEventListener('scroll', function(e){
    if(activePortal && e.target && e.target.closest && e.target.closest('.mf-feed, #mfFeed')){
      repositionPortals();
    }
  }, true);

  function boot(){
    refreshFeedCardMenus(document);
    hydrateEmptyMenus(document);
    observeOnMediaMenuContrast();
    syncOnMediaMenuContrast(document);
    var viewPostClose = document.getElementById('pcmViewPostClose');
    if(viewPostClose && !viewPostClose.__pcmBound){
      viewPostClose.__pcmBound = true;
      viewPostClose.addEventListener('click', function(e){
        if(e && e.preventDefault) e.preventDefault();
        closePcmViewPostOverlay();
      });
    }
    var viewPostOv = document.getElementById('pcmViewPostOverlay');
    if(viewPostOv && !viewPostOv.__pcmBound){
      viewPostOv.__pcmBound = true;
      viewPostOv.addEventListener('click', function(e){
        if(e.target === viewPostOv) closePcmViewPostOverlay();
      });
    }
    var confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
    if(confirmDeleteBtn && !confirmDeleteBtn.__pcmBound){
      confirmDeleteBtn.__pcmBound = true;
      confirmDeleteBtn.addEventListener('click', onConfirmDeleteClick);
    }
    var profileConfirmDeleteBtn = document.getElementById('profileConfirmDeleteBtn');
    if(profileConfirmDeleteBtn && !profileConfirmDeleteBtn.__pcmBound){
      profileConfirmDeleteBtn.__pcmBound = true;
      profileConfirmDeleteBtn.addEventListener('click', onProfileConfirmDeleteClick);
    }
    var genericConfirmDeleteBtn = document.getElementById('pcmGenericConfirmDeleteBtn');
    if(genericConfirmDeleteBtn && !genericConfirmDeleteBtn.__pcmBound){
      genericConfirmDeleteBtn.__pcmBound = true;
      genericConfirmDeleteBtn.addEventListener('click', function(ev){
        onGenericConfirmDeleteClick(ev && ev.target ? ev.target : genericConfirmDeleteBtn);
      });
    }
    var genericConfirmArchiveBtn = document.getElementById('pcmGenericConfirmArchiveBtn');
    if(genericConfirmArchiveBtn && !genericConfirmArchiveBtn.__pcmBound){
      genericConfirmArchiveBtn.__pcmBound = true;
      genericConfirmArchiveBtn.addEventListener('click', onGenericConfirmArchiveClick);
    }
    var genericDeleteModal = document.getElementById('pcmDeleteConfirmDialog');
    if(genericDeleteModal && !genericDeleteModal.__pcmDismissBound){
      genericDeleteModal.__pcmDismissBound = true;
      genericDeleteModal.querySelectorAll('[data-pcm-delete-dismiss]').forEach(function(btn){
        btn.addEventListener('click', function(){ hideModal('pcmDeleteConfirmDialog'); });
      });
      genericDeleteModal.addEventListener('click', function(e){
        if(e.target !== genericDeleteModal) return;
        var rect = genericDeleteModal.getBoundingClientRect();
        if(e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom){
          hideModal('pcmDeleteConfirmDialog');
        }
      });
    }
    var genericArchiveModal = document.getElementById('pcmArchiveConfirmDialog');
    if(genericArchiveModal && !genericArchiveModal.__pcmDismissBound){
      genericArchiveModal.__pcmDismissBound = true;
      genericArchiveModal.querySelectorAll('[data-pcm-archive-dismiss]').forEach(function(btn){
        btn.addEventListener('click', function(){ closePcmArchiveConfirm(); });
      });
      genericArchiveModal.addEventListener('click', function(e){
        if(e.target !== genericArchiveModal) return;
        var rect = genericArchiveModal.getBoundingClientRect();
        if(e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom){
          closePcmArchiveConfirm();
        }
      });
      genericArchiveModal.addEventListener('cancel', function(e){
        e.preventDefault();
        closePcmArchiveConfirm();
      });
    }
    var genericConfirmPrivateBtn = document.getElementById('pcmGenericConfirmPrivateBtn');
    if(genericConfirmPrivateBtn && !genericConfirmPrivateBtn.__pcmBound){
      genericConfirmPrivateBtn.__pcmBound = true;
      genericConfirmPrivateBtn.addEventListener('click', function(e){
        e.preventDefault();
        e.stopPropagation();
        var input = document.getElementById('pcmPrivatePostId');
        var postId = Number((input && input.value) || window.__pcmPendingPrivateId || 0);
        var done = window.__pcmPendingPrivateDone;
        closePcmPrivateConfirm();
        if(postId > 0){
          if(typeof done === 'function') done();
          else runSetVisibility(postId, 'private');
        }
      });
    }
    var privateConfirmModal = document.getElementById('pcmPrivateConfirmDialog');
    if(privateConfirmModal && !privateConfirmModal.__pcmDismissBound){
      privateConfirmModal.__pcmDismissBound = true;
      privateConfirmModal.querySelectorAll('[data-pcm-private-dismiss]').forEach(function(btn){
        btn.addEventListener('click', function(){ closePcmPrivateConfirm(); });
      });
      privateConfirmModal.addEventListener('click', function(e){
        if(e.target !== privateConfirmModal) return;
        var rect = privateConfirmModal.getBoundingClientRect();
        if(e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom){
          closePcmPrivateConfirm();
        }
      });
      privateConfirmModal.addEventListener('cancel', function(e){
        e.preventDefault();
        closePcmPrivateConfirm();
      });
    }
    var shareSheet = document.getElementById('pcmShareSheet');
    if(shareSheet && !shareSheet.__pcmDismissBound){
      shareSheet.__pcmDismissBound = true;
      shareSheet.querySelectorAll('[data-pcm-share-dismiss]').forEach(function(btn){
        btn.addEventListener('click', function(){ closePcmShareSheet(); });
      });
      shareSheet.querySelectorAll('[data-pcm-share]').forEach(function(btn){
        btn.addEventListener('click', function(e){
          e.stopPropagation();
          runShareTarget(btn.getAttribute('data-pcm-share'), e);
        });
      });
      var nativeBtn = document.getElementById('pcmShareNativeBtn');
      if(nativeBtn){
        nativeBtn.addEventListener('click', function(e){
          e.preventDefault();
          e.stopPropagation();
          runShareTarget('native', e);
        });
      }
      shareSheet.addEventListener('click', function(e){
        if(e.target !== shareSheet) return;
        var rect = shareSheet.getBoundingClientRect();
        if(e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom){
          closePcmShareSheet();
        }
      });
      shareSheet.addEventListener('cancel', function(e){
        e.preventDefault();
        closePcmShareSheet();
      });
    }
    var tagSheet = document.getElementById('pcmTagSheet');
    if(tagSheet && !tagSheet.__pcmDismissBound){
      tagSheet.__pcmDismissBound = true;
      tagSheet.querySelectorAll('[data-pcm-tag-dismiss]').forEach(function(btn){
        btn.addEventListener('click', function(){ closePcmTagSheet(); });
      });
      var tagSaveBtn = document.getElementById('pcmTagSaveBtn');
      if(tagSaveBtn){
        tagSaveBtn.addEventListener('click', function(e){
          e.preventDefault();
          e.stopPropagation();
          savePcmTags();
        });
      }
      ensurePcmTagger();
      tagSheet.addEventListener('click', function(e){
        if(e.target !== tagSheet) return;
        var rect = tagSheet.getBoundingClientRect();
        if(e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom){
          closePcmTagSheet();
        }
      });
      tagSheet.addEventListener('cancel', function(e){
        e.preventDefault();
        closePcmTagSheet();
      });
    }
    var mentionSheet = document.getElementById('pcmMentionSheet');
    if(mentionSheet && !mentionSheet.__pcmDismissBound){
      mentionSheet.__pcmDismissBound = true;
      mentionSheet.querySelectorAll('[data-pcm-mention-dismiss]').forEach(function(btn){
        btn.addEventListener('click', function(){ closePcmMentionSheet(); });
      });
      var mentionSendBtn = document.getElementById('pcmMentionSendBtn');
      if(mentionSendBtn){
        mentionSendBtn.addEventListener('click', function(e){
          e.preventDefault();
          e.stopPropagation();
          sendPcmMentions();
        });
      }
      ensurePcmMentioner();
      mentionSheet.addEventListener('click', function(e){
        if(e.target !== mentionSheet) return;
        var rect = mentionSheet.getBoundingClientRect();
        if(e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom){
          closePcmMentionSheet();
        }
      });
      mentionSheet.addEventListener('cancel', function(e){
        e.preventDefault();
        closePcmMentionSheet();
      });
    }
    var postDestDialog = document.getElementById('pcmPostDestDialog');
    if(postDestDialog && !postDestDialog.__pcmDismissBound){
      postDestDialog.__pcmDismissBound = true;
      postDestDialog.querySelectorAll('[data-pcm-post-dismiss]').forEach(function(btn){
        btn.addEventListener('click', function(){ closePcmPostDestDialog(); });
      });
      var friendsBtn = document.getElementById('pcmPostToFriendsBtn');
      if(friendsBtn){
        friendsBtn.addEventListener('click', function(e){
          e.preventDefault();
          e.stopPropagation();
          submitPcmPostDest('friends');
        });
      }
      var publicBtn = document.getElementById('pcmPostToPublicBtn');
      if(publicBtn){
        publicBtn.addEventListener('click', function(e){
          e.preventDefault();
          e.stopPropagation();
          submitPcmPostDest('public');
        });
      }
      postDestDialog.addEventListener('click', function(e){
        if(e.target !== postDestDialog) return;
        var rect = postDestDialog.getBoundingClientRect();
        if(e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom){
          closePcmPostDestDialog();
        }
      });
      postDestDialog.addEventListener('cancel', function(e){
        e.preventDefault();
        closePcmPostDestDialog();
      });
    }
    var renameSaveBtn = document.getElementById('pcmRenameSaveBtn');
    if(renameSaveBtn && !renameSaveBtn.__pcmBound){
      renameSaveBtn.__pcmBound = true;
      renameSaveBtn.addEventListener('click', onRenameSaveClick);
    }
  }

  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
  window.addEventListener('load', function(){
    refreshFeedCardMenus(document);
    hydrateEmptyMenus(document);
    syncOnMediaMenuContrast(document);
    boot();
  });

})(window);
