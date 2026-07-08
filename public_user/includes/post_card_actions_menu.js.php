(function(window){
  'use strict';

  var opts = window.MSBPostCardMenuOpts || {};
  var $ = window.jQuery || null;

  function escHtml(s){
    return String(s || '').replace(/[&<>"']/g, function(m){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
    });
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

  function positionPortal(btn, portal){
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

    var cloneItems = menuActionEls(clone);
    cloneItems.forEach(function(ci){
      ci.addEventListener('click', function(ev){
        ev.preventDefault();
        ev.stopPropagation();
        handleMenuItemAction(ci, ev);
        closeMenus();
      }, true);
    });

    document.body.appendChild(clone);
    positionPortal(btn, clone);
    activePortal = clone;
    activePortalWrap = wrap;
  }

  function closeMenus(except){
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

  function absolutePostUrl(postId, wrap){
    postId = Number(postId || 0);
    if(!postId) return '';
    var surface = '';
    if(wrap && wrap.getAttribute){
      surface = String(wrap.getAttribute('data-menu-surface') || '').trim();
    }
    if(!surface) surface = String(opts.menu_surface || 'public');
    if(surface === 'feed'){
      return (window.location.origin || '') + (window.location.pathname || '') + '?post=' + encodeURIComponent(String(postId));
    }
    var page = surface === 'profile' ? 'profile.php' : 'public.php';
    return (window.location.origin || '') + '/' + page.replace(/^\//, '') + '?post=' + encodeURIComponent(String(postId));
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

    document.querySelectorAll('.js-save-post[data-post-id="'+String(postId)+'"], .mf-card[data-id="'+String(postId)+'"] .mf-save').forEach(function(btn){
      btn.classList.toggle('is-save', saved);
    });
    document.querySelectorAll('.js-share-post[data-post-id="'+String(postId)+'"], .mf-card[data-id="'+String(postId)+'"] .mf-share').forEach(function(btn){
      btn.classList.toggle('is-share', shared);
    });
    document.querySelectorAll('.mf-card[data-id="'+String(postId)+'"] .mf-act.mf-save .msb-pact-bookmark').forEach(function(icon){
      icon.classList.toggle('is-active', saved);
    });

    if(typeof res.share_count !== 'undefined'){
      document.querySelectorAll('.public-post-card[data-post-id="'+String(postId)+'"] .js-share-count').forEach(function(el){
        el.textContent = String(res.share_count || 0);
      });
      document.querySelectorAll('.mf-card[data-id="'+String(postId)+'"] .mf-share .mf-num').forEach(function(el){
        el.textContent = String(res.share_count || 0);
      });
    }
    if(typeof res.save_count !== 'undefined'){
      document.querySelectorAll('.public-post-card[data-post-id="'+String(postId)+'"] .js-save-count').forEach(function(el){
        el.textContent = String(res.save_count || 0);
      });
      document.querySelectorAll('.mf-card[data-id="'+String(postId)+'"] .mf-save .mf-num').forEach(function(el){
        el.textContent = String(res.save_count || 0);
      });
    }

    syncBookmarkMenuState(postId, saved);

    if(typeof window.mfHydrateCard === 'function'){
      try { window.mfHydrateCard(postId, res.post || {}, counts, res.attachments || []); } catch(e){}
    }
  }

  function postTrack(action, postId, done){
    postId = Number(postId || 0);
    if(!postId) return;
    var url = trackApiUrl();
    if($){
      $.post(url, { ajax: action, post_id: postId }, function(res){
        if(res && res.ok !== false) syncPostTrackState(postId, res);
        if(typeof done === 'function') done(res);
      }, 'json');
      return;
    }
    var body = new URLSearchParams({ ajax: action, post_id: String(postId) });
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
    var editHref = 'dashboard.php?modal=1&edit=' + String(pid);
    var html = linkItem('pcm-edit', editHref, 'fa fa-edit', 'Edit', ' data-create-post-modal="1"');
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
    var html = '';

    if(!isOwner){
      html += buttonItem('pcm-report is-danger', 'fa fa-flag', 'Report', ' data-post-id="'+esc(String(pid))+'"');
    }
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
    if(!isFeedSurface()) return;
    root = root || document;
    root.querySelectorAll('.mf-card').forEach(function(card){
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

    if(isOwner && !staffReadonly){
      var ownerHtml = buildOwnerItems(it, pid, helpers);
      var ownerCommon = buildCommonItems(it, true, pid, helpers);
      if(ownerCommon) ownerHtml += menuDivider() + ownerCommon;
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
    var html = '';
    var feedSurface = isFeedSurface();
    var showPublisherView = isPublisher && isFollowing && profileUrl;

    if((!feedSurface || showPublisherView) && profileUrl){
      html += linkItem('pcm-view', profileUrl, 'fa fa-user', 'View');
    }
    if(!feedSurface && !isPublisher && friendStatus === 'friends'){
      html += linkItem('pcm-friends', 'contacts.php', 'fa fa-users', 'Friends');
    }
    if(friendStatus === 'friends'){
      html += linkItem('pcm-message', messageUrl, 'fa fa-comments', 'Message');
    }
    if(!feedSurface && !isPublisher && peerId && friendStatus === 'none' && !staffReadonly){
      html += '<button type="button" class="pcm-item pcm-add-friend" data-peer-id="' + esc(String(peerId)) + '" role="menuitem">' +
        '<i class="fa fa-user-plus" aria-hidden="true"></i><span>Add Friend</span></button>';
    }
    var canFollowPublishers = opts.can_follow_publishers !== false;
    var publisherWorkspaceViewer = !!opts.publisher_workspace_viewer;
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
    var helpers = window.MSBFeedMenuHelpers || window.MSBPublicMenuHelpers || {};
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
    return {
      id: Number(card.getAttribute('data-post-id') || card.getAttribute('data-id') || 0),
      user_id: Number(card.getAttribute('data-peer-id') || 0),
      author_id: Number(card.getAttribute('data-peer-id') || 0),
      friend_code: String(card.getAttribute('data-peer-code') || ''),
      username: String(card.getAttribute('data-peer-username') || ''),
      account_kind: String(card.getAttribute('data-account-kind') || 'personal'),
      is_following: Number(card.getAttribute('data-is-following') || 0),
      friend_status: String(card.getAttribute('data-friend-status') || 'none'),
      is_publisher: Number(card.getAttribute('data-is-publisher') || 0),
      contact_id: Number(card.getAttribute('data-contact-id') || 0),
      contact_name: String(card.getAttribute('data-contact-name') || ''),
      profile_url: String(card.getAttribute('data-profile-url') || ''),
      my_saved: Number(card.getAttribute('data-my-saved') || 0),
      is_saved: Number(card.getAttribute('data-my-saved') || 0),
      is_archived: Number(card.getAttribute('data-is-archived') || 0),
      my_archived: Number(card.getAttribute('data-is-archived') || 0)
    };
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
    if(usePortal){
      openPortalMenu(wrap, btn, menu);
      return;
    }
    menu.classList.add('open');
    btn.setAttribute('aria-expanded', 'true');
  }

  function showModal(id){
    if($ && $.fn && $.fn.modal){
      $('#' + id).modal('show');
      return;
    }
    var el = document.getElementById(id);
    if(el) el.style.display = 'block';
  }

  function hideModal(id){
    if($ && $.fn && $.fn.modal){
      $('#' + id).modal('hide');
      return;
    }
    var el = document.getElementById(id);
    if(el) el.style.display = 'none';
  }

  function removePostFromSurfaces(postId){
    postId = Number(postId || 0);
    if(!postId) return;
    document.querySelectorAll('.mf-card[data-id="'+String(postId)+'"], .public-post-card[data-post-id="'+String(postId)+'"]').forEach(function(el){
      try { el.remove(); } catch(e){}
    });
    if(window.TTStories && typeof window.TTStories.removePost === 'function'){
      try { window.TTStories.removePost(postId); } catch(e){}
    }
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

  function runArchive(postId, archived, done){
    postId = Number(postId || 0);
    if(!postId) return;
    var url = trackApiUrl();
    var body = new URLSearchParams({ ajax: 'archive', post_id: String(postId), archived: archived ? '1' : '0' });
    fetch(url, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body })
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
            pcmToast('Post archived.');
          } else {
            pcmToast('Post unarchived.');
          }
        }
        if(typeof done === 'function') done(res);
      })
      .catch(function(){ if(typeof done === 'function') done(null); });
  }

  function confirmArchive(btn){
    if(!btn) return;
    var postId = Number(btn.getAttribute('data-post-id') || 0);
    if(!postId) return;
    var isArchived = String(btn.getAttribute('data-archived') || '0') === '1';
    var nextArchived = !isArchived;
    if(nextArchived && !window.confirm('Archive this post? It will be hidden from feeds.')) return;
    runArchive(postId, nextArchived);
  }

  function confirmDelete(postId, done){
    postId = Number(postId || 0);
    if(!postId) return;
    var mode = String(opts.delete_mode || 'confirm');
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
    if(!postId) return;
    var mode = String(opts.delete_mode || 'feed');
    if(mode === 'feed' && opts.api_url){
      if($){
        $.post(opts.api_url, { ajax:'delete_post', post_id: postId }, function(res){
          if(res && res.ok !== false){
            removePostFromSurfaces(postId);
            try { if(typeof window.refreshList === 'function') window.refreshList(false); } catch(e){}
          }
          if(typeof done === 'function') done(res);
        }, 'json');
        return;
      }
      var body = new URLSearchParams({ ajax:'delete_post', post_id: String(postId) });
      fetch(opts.api_url, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body })
        .then(function(r){ return r.json(); })
        .then(function(res){
          if(res && res.ok !== false){
            removePostFromSurfaces(postId);
            try { if(typeof window.refreshList === 'function') window.refreshList(false); } catch(e){}
          }
          if(typeof done === 'function') done(res);
        });
      return;
    }
    if(mode === 'profile' && typeof window.mfPost === 'function'){
      window.mfPost('delete_post', { post_id: postId }, function(res){
        if(res && res.ok){
          document.querySelectorAll('#profilePostsFeed .mf-card[data-id="'+String(postId)+'"]').forEach(function(el){ el.remove(); });
        }
        if(typeof done === 'function') done(res);
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

  function handleMenuItemAction(target, e){
    if(!target) return false;
    e = e || {};

    var editLink = closest(target, menuItemSel('.pcm-edit'));
    if(editLink){
      if(e.preventDefault) e.preventDefault();
      if(e.stopPropagation) e.stopPropagation();
      closeMenus();
      var href = String(editLink.getAttribute('href') || '').trim();
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
      closeMenus();
      confirmDelete(delBtn.getAttribute('data-post-id'));
      return true;
    }

    var archiveBtn = closest(target, menuItemSel('.pcm-archive'));
    if(archiveBtn){
      if(e.preventDefault) e.preventDefault();
      if(e.stopPropagation) e.stopPropagation();
      closeMenus();
      confirmArchive(archiveBtn);
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
        if(typeof window.applyStatusForPeer === 'function'){
          window.applyStatusForPeer(peerId, String(res.status));
        } else if(typeof window.msbApplyFriendActionBtnState === 'function'){
          document.querySelectorAll('.friend-btn[data-peer-id="'+String(peerId)+'"]').forEach(function(btn){
            window.msbApplyFriendActionBtnState(btn, String(res.status));
          });
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
      closeMenus();
      var bookmarkPid = Number(bookmarkBtn.getAttribute('data-post-id') || 0);
      if(!bookmarkPid) return true;
      postTrack('save', bookmarkPid);
      return true;
    }

    var shareBtn = closest(target, menuItemSel('.pcm-share'));
    if(shareBtn){
      if(e.preventDefault) e.preventDefault();
      if(e.stopPropagation) e.stopPropagation();
      closeMenus();
      var sharePid = Number(shareBtn.getAttribute('data-post-id') || 0);
      if(!sharePid) return true;
      var shareWrap = closest(shareBtn, '.post-card-menu-wrap, .mf-menu-wrap');
      copyText(absolutePostUrl(sharePid, shareWrap));
      postTrack('share', sharePid);
      return true;
    }

    var copyBtn = closest(target, menuItemSel('.pcm-copy-link'));
    if(copyBtn){
      if(e.preventDefault) e.preventDefault();
      if(e.stopPropagation) e.stopPropagation();
      closeMenus();
      var copyPid = Number(copyBtn.getAttribute('data-post-id') || 0);
      if(!copyPid) return true;
      var copyWrap = closest(copyBtn, '.post-card-menu-wrap, .mf-menu-wrap');
      copyText(absolutePostUrl(copyPid, copyWrap)).then(function(){
        pcmToast('Link copied to clipboard.');
      });
      return true;
    }

    return false;
  }

  function onDocumentClick(e){
    var target = e.target;
    if(!target) return;

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
    runDelete(postId, function(){
      window.__pcmPendingDeleteId = 0;
      hideModal('profileDeleteConfirmModal');
    });
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
    refreshFeedCardMenus: refreshFeedCardMenus,
    closeAll: closeMenus,
    runDelete: runDelete,
    hydrate: hydrateEmptyMenus,
    syncOnMediaContrast: syncOnMediaMenuContrast,
    syncCardPublisher: syncCardPublisher,
    syncPublisherCards: syncPublisherCards,
    syncBookmarkMenuState: syncBookmarkMenuState,
    syncArchiveMenuState: syncArchiveMenuState,
    syncPostTrackState: syncPostTrackState
  };

  document.addEventListener('click', onDocumentClick, true);
  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') closeMenus();
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
