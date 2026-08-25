<?php
declare(strict_types=1);
if (!empty($GLOBALS['msb_reactors_modal_included'])) {
    return;
}
$GLOBALS['msb_reactors_modal_included'] = true;
?>
<style id="post-reactors-modal-css">
.msb-react-cluster{
  display:inline-flex;align-items:center;gap:6px;
}
.msb-react-cluster .js-open-reactors,
.msb-react-cluster .js-love-count,
.msb-react-cluster .js-share-count,
.msb-react-cluster .js-save-count,
button.action-count.js-love-count,
button.js-open-reactors{
  background:none !important;
  border:0 !important;
  outline:none !important;
  box-shadow:none !important;
  -webkit-appearance:none !important;
  appearance:none !important;
  padding:0;margin:0;font:inherit;color:inherit;line-height:1;cursor:pointer;
}
.msb-react-cluster .js-open-reactors:focus,
.msb-react-cluster .js-open-reactors:focus-visible,
.msb-react-cluster .js-love-count:focus,
.msb-react-cluster .js-love-count:focus-visible,
.msb-react-cluster .js-share-count:focus,
.msb-react-cluster .js-save-count:focus{
  outline:none !important;
  box-shadow:none !important;
  border:0 !important;
}
html body dialog#msbRxOverlay.pcm-share-dialog,
html body dialog#msbRxOverlay{
  position:fixed!important;inset:0!important;top:0!important;right:0!important;bottom:0!important;left:0!important;
  width:min(820px,calc(100vw - 32px))!important;max-width:820px!important;height:max-content!important;
  max-height:min(88dvh,640px)!important;margin:auto!important;padding:16px 14px 14px!important;
  overflow:auto!important;transform:none!important;
  border:1px solid var(--msb-palette-border,rgba(148,163,184,.28))!important;border-radius:14px!important;
  background:var(--msb-palette-surface,var(--msb-palette-bg,#fff))!important;
  color:var(--msb-palette-text,#111827)!important;
  box-shadow:0 18px 48px rgba(0,0,0,.28)!important;text-align:left!important;
  box-sizing:border-box!important;z-index:2147483647!important;
}
html body dialog#msbRxOverlay:not([open]){display:none!important;visibility:hidden!important;}
html body dialog#msbRxOverlay[open]{display:block!important;visibility:visible!important;}
#msbRxOverlay::backdrop{background:rgba(15,23,42,.58);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);}
#msbRxOverlay .pcm-share-close{
  position:absolute!important;top:10px!important;right:10px!important;width:28px!important;height:28px!important;
  margin:0!important;padding:0!important;border:0!important;border-radius:50%!important;background:transparent!important;
  color:var(--msb-palette-text-muted,#64748b)!important;font-size:18px!important;line-height:28px!important;cursor:pointer!important;
  display:inline-flex!important;align-items:center!important;justify-content:center!important;
}
#msbRxOverlay h2{margin:2px 32px 4px 2px!important;padding:0!important;font-size:15px!important;font-weight:700!important;line-height:1.3!important;color:inherit!important;}
#msbRxOverlay .pcm-share-sub{margin:0 2px 12px!important;font-size:12px!important;font-weight:500!important;color:var(--msb-palette-text-muted,#64748b)!important;line-height:1.45!important;}
.msb-rx-tabs{
  display:flex;align-items:center;justify-content:space-between;gap:0;
  margin:0 0 10px;padding:0 0 8px;
  border-bottom:1px solid var(--msb-palette-border,rgba(148,163,184,.28));
  overflow:visible;
}
.msb-rx-tab{
  flex:1 1 0;min-width:0;border:0;background:transparent;
  color:var(--msb-palette-text-muted,#64748b);
  padding:8px 4px 7px;font-size:12px;font-weight:700;cursor:pointer;
  border-bottom:2px solid transparent;white-space:nowrap;
  display:inline-flex;align-items:center;justify-content:center;gap:3px;
}
.msb-rx-tab.is-active{
  color:var(--msb-palette-text,#111827);
  border-bottom-color:#2563eb;
}
.msb-rx-tab .msb-pact{
  width:15px;height:15px;min-width:15px;min-height:15px;flex:0 0 15px;
  filter:none !important;background:currentColor;color:currentColor;
}
.msb-rx-tab .msb-rx-face,
.msb-rx-tab .msb-rx-emoji{
  width:20px;height:20px;min-width:20px;flex:0 0 20px;
  display:inline-flex;align-items:center;justify-content:center;
  font-size:16px;line-height:1;
}
.msb-rx-tab .msb-rx-face svg{width:20px;height:20px;display:block;}
.msb-rx-tab[data-rx-filter="love"] .msb-pact{color:#ff4d6d;}
.msb-rx-tab[data-rx-filter="like"] .msb-pact{color:#2563eb;}
.msb-rx-tab[data-rx-filter="dislike"] .msb-pact{color:#64748b;}
@media (max-width:639.98px){
  html body dialog#msbRxOverlay.pcm-share-dialog,
  html body dialog#msbRxOverlay{
    width:min(820px,calc(100vw - 16px))!important;max-width:calc(100vw - 16px)!important;
  }
  .msb-rx-tabs{overflow-x:auto;justify-content:flex-start;scrollbar-width:none;}
  .msb-rx-tab{flex:0 0 auto;padding:8px 7px 7px;}
}
.msb-rx-body{
  max-height:min(46vh,380px);overflow-y:auto;margin:0 -4px 10px;padding:2px 0 4px;
}
.msb-rx-empty{
  margin:18px 8px;text-align:center;
  color:var(--msb-palette-text-muted,#64748b);font-size:13px;font-weight:500;line-height:1.45;
}
.msb-rx-row{
  display:flex;align-items:center;gap:10px;
  padding:8px 8px;border-radius:12px;
}
.msb-rx-row:hover{background:var(--msb-palette-hover-bg,rgba(148,163,184,.10));}
.msb-rx-av-wrap{position:relative;flex:0 0 40px;width:40px;height:40px;}
.msb-rx-av{
  width:40px;height:40px;border-radius:50%;object-fit:cover;display:block;
  background:var(--msb-palette-hover-bg,rgba(148,163,184,.18));
}
.msb-rx-badge{
  position:absolute;right:-2px;bottom:-2px;width:18px;height:18px;border-radius:50%;
  background:var(--msb-palette-surface,var(--msb-palette-bg,#fff));
  border:1px solid var(--msb-palette-border,rgba(148,163,184,.28));
  display:flex;align-items:center;justify-content:center;
}
.msb-rx-badge .msb-pact{
  width:11px;height:11px;min-width:11px;min-height:11px;flex:0 0 11px;
  filter:none !important;background:currentColor;color:#475569;
}
.msb-rx-badge .msb-rx-face{width:14px;height:14px;display:flex;}
.msb-rx-badge .msb-rx-face svg{width:14px;height:14px;display:block;}
.msb-rx-badge .msb-pact-heart{color:#ff4d6d;}
.msb-rx-badge .msb-pact-thumb{color:#2563eb;}
.msb-rx-name{
  flex:1 1 auto;min-width:0;
  color:var(--msb-palette-text,#111827);font-size:13px;font-weight:700;
  text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.msb-rx-name:hover{text-decoration:underline;color:inherit;}
.msb-rx-add{
  flex:0 0 auto;height:30px;border-radius:999px;
  border:1px solid var(--msb-palette-border,rgba(148,163,184,.38));
  background:var(--msb-palette-hover-bg,rgba(148,163,184,.10));
  color:var(--msb-palette-text,#111827);
  padding:0 12px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;
}
.msb-rx-add:hover{background:var(--msb-palette-surface-2,rgba(148,163,184,.16));}
.msb-rx-add:disabled{opacity:.7;cursor:default;}
.js-love-count,.js-like-count,.js-reaction-count,.js-share-count,.js-save-count,
.mf-act.mf-love .mf-num[data-count="love"],
.reel-act-count[data-count="love"],
.reel-act-count[data-count="share"],
.reel-act-count[data-count="save"]{cursor:pointer;}
</style>
<dialog class="pcm-share-dialog" id="msbRxOverlay" aria-labelledby="msbRxTitle">
  <button type="button" class="pcm-share-close" id="msbRxClose" aria-label="Close">&times;</button>
  <h2 id="msbRxTitle">People</h2>
  <p class="pcm-share-sub" id="msbRxSub">See who reacted, shared, or saved this post.</p>
  <div class="msb-rx-tabs" id="msbRxTabs"></div>
  <div class="msb-rx-body" id="msbRxBody"></div>
</dialog>
<script>
(function(){
  var overlay = document.getElementById('msbRxOverlay');
  var tabsEl = document.getElementById('msbRxTabs');
  var bodyEl = document.getElementById('msbRxBody');
  var closeBtn = document.getElementById('msbRxClose');
  var titleEl = document.getElementById('msbRxTitle');
  if(!overlay || !tabsEl || !bodyEl) return;
  var currentPostId = 0;
  var currentFilter = 'all';
  var cache = { counts: {}, people: [] };
  var pinned = ['love','like','dislike','smile','laugh','wow','sad','angry','clap','share','save'];
  var faceSvg = {
    smile: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="#FACC15"/><path d="M7 10.6c.9-1.5 2.6-1.5 3.5 0" fill="none" stroke="#111" stroke-width="1.7" stroke-linecap="round"/><path d="M13.5 10.6c.9-1.5 2.6-1.5 3.5 0" fill="none" stroke="#111" stroke-width="1.7" stroke-linecap="round"/><path d="M8 14.2c1.35 2.5 6.65 2.5 8 0" fill="none" stroke="#111" stroke-width="1.75" stroke-linecap="round"/></svg>',
    laugh: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="#FACC15"/><path d="M6.2 8.2l3.2 2.3-3.2 2.3" fill="none" stroke="#111" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="M17.8 8.2l-3.2 2.3 3.2 2.3" fill="none" stroke="#111" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="M7.4 13.8c.5 4.4 8.7 4.4 9.2 0-.3-1.15-2.3-1.95-4.6-1.95s-4.3.8-4.6 1.95z" fill="#111"/><ellipse cx="12" cy="16.85" rx="3.1" ry="1.55" fill="#EF4444"/></svg>',
    wow: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="#FACC15"/><path d="M6.4 7.4c1.1-1.35 3-1.35 4.1 0" fill="none" stroke="#111" stroke-width="1.45" stroke-linecap="round"/><path d="M13.5 7.4c1.1-1.35 3-1.35 4.1 0" fill="none" stroke="#111" stroke-width="1.45" stroke-linecap="round"/><circle cx="8.6" cy="10.7" r="1.45" fill="#111"/><circle cx="15.4" cy="10.7" r="1.45" fill="#111"/><ellipse cx="12" cy="16.2" rx="2.15" ry="2.85" fill="#111"/></svg>',
    sad: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="#FACC15"/><path d="M6.3 8.1c1.2-1.2 3.2-.85 4.1.45" fill="none" stroke="#111" stroke-width="1.4" stroke-linecap="round"/><path d="M13.6 8.55c.9-1.3 2.9-1.65 4.1-.45" fill="none" stroke="#111" stroke-width="1.4" stroke-linecap="round"/><circle cx="8.7" cy="11" r="1.2" fill="#111"/><circle cx="15.3" cy="11" r="1.2" fill="#111"/><path d="M8.6 16.4c1.2-1.7 5.6-1.7 6.8 0" fill="none" stroke="#111" stroke-width="1.55" stroke-linecap="round"/><path d="M16.4 14.8c1.35 1.1 1.55 2.85.15 3.85-1.55-.55-2.05-2.05-.15-3.85z" fill="#60A5FA"/></svg>',
    angry: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><defs><linearGradient id="msbRxAg" x1="12" y1="1" x2="12" y2="23" gradientUnits="userSpaceOnUse"><stop stop-color="#DC2626"/><stop offset="1" stop-color="#FBBF24"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#msbRxAg)"/><path d="M5.8 8.8l4.4 1.7" fill="none" stroke="#111" stroke-width="1.85" stroke-linecap="round"/><path d="M18.2 8.8l-4.4 1.7" fill="none" stroke="#111" stroke-width="1.85" stroke-linecap="round"/><circle cx="8.6" cy="11.6" r="1.15" fill="#111"/><circle cx="15.4" cy="11.6" r="1.15" fill="#111"/><path d="M9.4 16.3c.9-.7 4.3-.7 5.2 0" fill="none" stroke="#111" stroke-width="1.7" stroke-linecap="round"/></svg>'
  };
  var faces = {
    like:'👍', love:'❤️', laugh:'😂', smile:'☺', wow:'😮', sad:'😢', angry:'😡', clap:'👏', dislike:'👎', share:'↗', save:'🔖'
  };
  var pact = {
    love:'heart', like:'thumb', dislike:'thumb-down', share:'share', save:'bookmark'
  };
  var titles = {
    all:'People', love:'Loved this', like:'Liked this', dislike:'Disliked this',
    smile:'Smiled', laugh:'Laughed', wow:'Wowed', sad:'Sad', angry:'Angry', clap:'Clapped',
    share:'Shared this', save:'Favorited this'
  };

  function esc(s){
    return String(s||'').replace(/[&<>"']/g, function(m){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
    });
  }
  function fmt(n){
    n = Number(n||0);
    if(n >= 1000000) return (Math.round(n/100000)/10) + 'M';
    if(n >= 1000) return (Math.round(n/100)/10) + 'K';
    return String(n);
  }
  function friendLabel(status){
    if(status === 'self') return '';
    if(status === 'friends') return 'Friends';
    if(status === 'outgoing_pending') return 'Requested';
    if(status === 'incoming_pending') return 'Respond';
    return 'Add friend';
  }
  function tabIcon(key){
    if(faceSvg[key]){
      return '<span class="msb-rx-face" aria-hidden="true">'+faceSvg[key]+'</span>';
    }
    if(pact[key]){
      return '<i class="msb-pact msb-pact-'+pact[key]+'" aria-hidden="true"></i>';
    }
    return '<span class="msb-rx-emoji">'+(faces[key]||'')+'</span>';
  }
  function kindBadge(kind){
    if(faceSvg[kind]){
      return '<span class="msb-rx-face" aria-hidden="true">'+faceSvg[kind]+'</span>';
    }
    if(pact[kind]){
      return '<i class="msb-pact msb-pact-'+pact[kind]+'" aria-hidden="true"></i>';
    }
    return faces[kind]||'';
  }

  function renderTabs(){
    var html = '<button type="button" class="msb-rx-tab'+(currentFilter==='all'?' is-active':'')+'" data-rx-filter="all">All</button>';
    pinned.forEach(function(key){
      var n = Number(cache.counts[key]||0);
      html += '<button type="button" class="msb-rx-tab'+(currentFilter===key?' is-active':'')+'" data-rx-filter="'+esc(key)+'" aria-label="'+esc(titles[key]||key)+'">'+
        tabIcon(key)+fmt(n)+
      '</button>';
    });
    tabsEl.innerHTML = html;
    if(titleEl) titleEl.textContent = titles[currentFilter] || 'People';
  }

  function renderPeople(){
    var list = cache.people || [];
    if(!list.length){
      bodyEl.innerHTML = '<p class="msb-rx-empty">No one yet.</p>';
      return;
    }
    bodyEl.innerHTML = list.map(function(p){
      var st = String(p.friend_status||'none');
      var label = friendLabel(st);
      var btn = '';
      if(label){
        btn = '<button type="button" class="msb-rx-add" data-peer-id="'+Number(p.id||0)+'" data-status="'+esc(st)+'"'+(st==='friends'||st==='outgoing_pending'?' disabled':'')+'>'+esc(label)+'</button>';
      }
      return '<div class="msb-rx-row">'+
        '<a class="msb-rx-av-wrap" href="'+esc(p.profile||'#')+'">'+
          '<img class="msb-rx-av" src="'+esc(p.avatar||'')+'" alt="">'+
          '<span class="msb-rx-badge">'+kindBadge(p.reaction)+'</span>'+
        '</a>'+
        '<a class="msb-rx-name" href="'+esc(p.profile||'#')+'">'+esc(p.name||'User')+'</a>'+
        btn+
      '</div>';
    }).join('');
  }

  function load(postId, filter){
    currentPostId = Number(postId||0);
    currentFilter = filter || 'all';
    if(!currentPostId) return;
    if(titleEl) titleEl.textContent = titles[currentFilter] || 'People';
    bodyEl.innerHTML = '<p class="msb-rx-empty">Loading…</p>';
    fetch('ajax/post_reactors.php?post_id='+encodeURIComponent(String(currentPostId))+'&reaction='+encodeURIComponent(currentFilter)+'&tab='+encodeURIComponent(currentFilter), {
      credentials:'same-origin',
      cache:'no-store'
    }).then(function(r){ return r.json(); }).then(function(res){
      if(!res || !res.ok){
        bodyEl.innerHTML = '<p class="msb-rx-empty">Unable to load.</p>';
        return;
      }
      cache.counts = res.counts || {};
      cache.people = res.people || [];
      renderTabs();
      renderPeople();
    }).catch(function(){
      bodyEl.innerHTML = '<p class="msb-rx-empty">Unable to load.</p>';
    });
  }

  function open(postId, filter){
    if(overlay.parentNode !== document.body) document.body.appendChild(overlay);
    try{
      if(typeof overlay.showModal === 'function'){
        if(!overlay.open) overlay.showModal();
      } else {
        overlay.setAttribute('open', '');
      }
    }catch(e){
      overlay.setAttribute('open', '');
    }
    load(postId, filter || 'all');
  }
  function close(){
    try{
      if(typeof overlay.close === 'function' && overlay.open) overlay.close();
      else overlay.removeAttribute('open');
    }catch(e){
      overlay.removeAttribute('open');
    }
  }

  function inferTab(count){
    var tab = String(count.getAttribute('data-rx-tab') || '').toLowerCase();
    if(tab) return tab;
    if(count.classList.contains('js-love-count')) return 'love';
    if(count.classList.contains('js-like-count')) return 'like';
    if(count.classList.contains('js-share-count')) return 'share';
    if(count.classList.contains('js-save-count')) return 'save';
    if(count.closest('.js-share-post, .pv-act-share, .mf-act.mf-share, .reel-act[data-act="share"]')) return 'share';
    if(count.closest('.js-save-post, .pv-act-save, .mf-act.mf-save, .reel-act[data-act="save"]')) return 'save';
    if(count.closest('.pv-act-share, #pvShare')) return 'share';
    if(count.closest('.pv-act-save, #pvSave')) return 'save';
    if(count.closest('.pv-act-like, #pvLike')) return 'like';
    if(count.closest('.pv-act-love, #pvLove')) return 'love';
    var wrap = count.closest('.reel-act-wrap');
    if(wrap){
      var wrapKind = String((wrap.querySelector('[data-count]') || count).getAttribute('data-count') || '').toLowerCase();
      if(wrapKind && wrapKind !== 'comment') return wrapKind;
    }
    var dataCount = String(count.getAttribute('data-count') || '').toLowerCase();
    if(dataCount) return dataCount;
    return 'all';
  }
  function isCountTarget(el){
    if(!el || !el.closest) return null;
    if(el.closest('.js-comment-count-inline, .js-comment-count, .mf-cmt, [data-count="comment"]')) return null;
    var count = el.closest('.js-open-reactors, .js-love-count, .js-like-count, .js-reaction-count, .js-share-count, .js-save-count, #pvLoveN, #pvLikeN, #pvShareN, #pvSaveN');
    if(count && !count.classList.contains('js-comment-count-inline') && count.id !== 'pvComN') return count;
    var num = el.closest('.mf-num, .reel-act-count, .action-count, .pv-n');
    if(!num) return null;
    if(num.classList.contains('js-comment-count-inline') || num.id === 'pvCmtN' || num.id === 'pvComN') return null;
    var kind = String(num.getAttribute('data-count') || '').toLowerCase();
    if(kind === 'comment') return null;
    if(num.closest('.reel-act-wrap') && (kind === 'love' || kind === 'like' || kind === 'share' || kind === 'save' || kind === 'dislike')) return num;
    var wrap = num.closest('.js-react-love, .js-react-like, .js-share-post, .js-save-post, .mf-act.mf-love, .mf-act.mf-like, .mf-act.mf-share, .mf-act.mf-save, .reel-act[data-act="love"], .pv-act-love, .pv-act-share, .pv-act-save');
    return wrap ? num : null;
  }

  document.addEventListener('click', function(e){
    var count = isCountTarget(e.target);
    if(!count) return;
    var postId = Number(count.getAttribute('data-post-id') || 0);
    if(!postId){
      var host = count.closest('[data-post-id], .public-post-card, .mf-card, .reel-slide, .post, #pvOverlay');
      if(host) postId = Number(host.getAttribute('data-post-id') || 0);
    }
    if(!postId && window.pvPostId) postId = Number(window.pvPostId || 0);
    if(!postId) return;
    e.preventDefault();
    e.stopPropagation();
    if(e.stopImmediatePropagation) e.stopImmediatePropagation();
    open(postId, inferTab(count));
  }, true);

  tabsEl.addEventListener('click', function(e){
    var tab = e.target.closest('[data-rx-filter]');
    if(!tab) return;
    load(currentPostId, tab.getAttribute('data-rx-filter') || 'all');
  });
  bodyEl.addEventListener('click', function(e){
    var add = e.target.closest('.msb-rx-add');
    if(!add) return;
    var peerId = Number(add.getAttribute('data-peer-id') || 0);
    var status = add.getAttribute('data-status') || 'none';
    if(!peerId || add.disabled) return;
    if(status === 'incoming_pending'){
      window.location.href = 'contact_requests.php';
      return;
    }
    add.disabled = true;
    var fd = new FormData();
    fd.append('peer_id', String(peerId));
    fd.append('action', 'send');
    fetch('ajax/friend_action.php', { method:'POST', body:fd, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(res){
        if(res && (res.ok || res.status === 'outgoing_pending' || res.status === 'friends')){
          add.textContent = res.status === 'friends' ? 'Friends' : 'Requested';
          add.setAttribute('data-status', res.status || 'outgoing_pending');
          return;
        }
        add.disabled = false;
      })
      .catch(function(){ add.disabled = false; });
  });
  if(closeBtn) closeBtn.addEventListener('click', close);
  overlay.addEventListener('click', function(e){
    if(e.target !== overlay) return;
    var rect = overlay.getBoundingClientRect();
    if(e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom){
      close();
    }
  });
  overlay.addEventListener('cancel', function(e){
    e.preventDefault();
    close();
  });
})();
</script>
