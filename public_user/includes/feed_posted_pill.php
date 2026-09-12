<?php
declare(strict_types=1);
$feedPostedViewerId = isset($meId) ? (int)$meId : 0;
$feedPostedPageMode = isset($isNewsSurface) && !empty($isNewsSurface) ? 'news' : (isset($discoverTab) ? 'public' : 'feed');
?>
<button type="button" class="feed-posted-pill" aria-label="View newest posts" data-viewer-id="<?= $feedPostedViewerId ?>" data-page-mode="<?= htmlspecialchars($feedPostedPageMode, ENT_QUOTES, 'UTF-8') ?>">
  <span class="feed-posted-arrow" aria-hidden="true">↑</span>
  <span class="feed-posted-faces" aria-hidden="true">
    <span class="feed-posted-face"></span>
    <span class="feed-posted-face"></span>
    <span class="feed-posted-face"></span>
  </span>
  <span class="feed-posted-label"><?= htmlspecialchars(function_exists('app_t') ? app_t('posted') : 'posted', ENT_QUOTES, 'UTF-8') ?></span>
</button>

<style id="feed-posted-pill-css">
.feed-desktop-center{position:relative!important}
.feed-posted-pill{
  position:absolute;
  z-index:130;
  top:58px;
  left:50%;
  transform:translateX(-50%);
  min-width:0;
  height:42px;
  padding:4px 14px 4px 12px;
  border:0;
  border-radius:999px;
  background:#1d9bf0;
  color:#fff;
  box-shadow:0 8px 22px rgba(15,23,42,.22);
  display:flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  cursor:pointer;
  opacity:0;
  visibility:hidden;
  pointer-events:none;
  transition:opacity .16s ease, transform .16s ease, box-shadow .16s ease;
  margin-top: 15px;
}
.feed-posted-pill.is-visible{
  opacity:1;
  visibility:visible;
  pointer-events:auto;
}
.feed-posted-pill.is-dismissed{
  transition:none !important;
  opacity:0 !important;
  visibility:hidden !important;
  pointer-events:none !important;
  display:none !important;
}
.feed-posted-pill:hover,
.feed-posted-pill:focus{
  background:#168de0;
  color:#fff;
  box-shadow:0 10px 26px rgba(15,23,42,.28);
  outline:none;
}
.feed-posted-arrow{
  flex:0 0 auto;
  font-family:Arial,sans-serif;
  font-size:25px;
  font-weight:300;
  line-height:1;
  transform:translateY(-1px);
}
.feed-posted-faces{
  display:flex;
  align-items:center;
  height:32px;
}
.feed-posted-face{
  width:31px;
  height:31px;
  margin-left:-2px;
  border:0;
  border-radius:50%;
  overflow:hidden;
  background:transparent;
  box-sizing:border-box;
}
.feed-posted-face:first-child{margin-left:0}
.feed-posted-face img{
  display:block;
  width:100%;
  height:100%;
  object-fit:cover;
  border-radius:50%;
}
.feed-posted-face:empty{display:none}
.feed-posted-label{
  font-size:16px;
  font-weight:400;
  line-height:1;
  white-space:nowrap;
}
@media (max-width:767.98px){
  .feed-posted-pill{
    top:54px;
    min-width:0;
    height:38px;
    padding:4px 12px 4px 10px;
    gap:7px;
  }
  .feed-posted-arrow{font-size:22px}
  .feed-posted-faces{height:29px}
  .feed-posted-face{width:28px;height:28px}
  .feed-posted-label{font-size:15px}
}
</style>

<script>
(function(){
  var pills = document.querySelectorAll('.feed-posted-pill');
  var pill = pills.length ? pills[0] : null;
  for(var duplicateIndex = 1; duplicateIndex < pills.length; duplicateIndex++){
    if(pills[duplicateIndex] && pills[duplicateIndex].parentNode){
      pills[duplicateIndex].parentNode.removeChild(pills[duplicateIndex]);
    }
  }
  if(!pill || pill.dataset.bound === '1') return;
  pill.dataset.bound = '1';
  var viewerId = Number(pill.getAttribute('data-viewer-id') || 0);
  var pageMode = String(pill.getAttribute('data-page-mode') || 'feed');
  var newestKnownId = 0;
  var checking = false;
  var dismissed = false;

  function avatarSources(){
    var out = [];
    document.querySelectorAll('#mfFeed .mf-card .mf-avatar img, .ig-feed .public-post-card .avatar img, .public-post-card .standard-text-avatar img').forEach(function(img){
      var src = String(img.currentSrc || img.getAttribute('src') || '').trim();
      if(src && out.indexOf(src) === -1) out.push(src);
    });
    return out.slice(0, 3);
  }
  function paint(forcedSources){
    var srcs = Array.isArray(forcedSources) && forcedSources.length ? forcedSources : avatarSources();
    var faces = pill.querySelectorAll('.feed-posted-face');
    faces.forEach(function(face, i){
      var wanted = srcs[i] || '';
      var current = face.querySelector('img');
      var currentSrc = current ? String(current.currentSrc || current.getAttribute('src') || '') : '';
      if(wanted === currentSrc || (!wanted && !current)) return;
      face.innerHTML = wanted ? '<img src="' + wanted.replace(/&/g,'&amp;').replace(/"/g,'&quot;') + '" alt="">' : '';
    });
  }
  function newestDomId(){
    var newest = 0;
    document.querySelectorAll('#mfFeed .mf-card[data-id], #mfFeed .mf-card[data-post-id], .ig-feed .public-post-card[data-post-id], .public-post-card[data-post-id]').forEach(function(card){
      newest = Math.max(newest, Number(card.getAttribute('data-post-id') || card.getAttribute('data-id') || 0));
    });
    return newest;
  }
  function showInitial(){
    paint();
    newestKnownId = Math.max(newestKnownId, newestDomId());
  }
  function checkForNewPosts(){
    if(checking || document.hidden) return;
    checking = true;
    var url = 'feed_api.php?ajax=list&limit=8&order=created&exclude_stories=1&page=' + encodeURIComponent(pageMode) + '&_=' + Date.now();
    fetch(url, {credentials:'same-origin', cache:'no-store'})
      .then(function(res){ return res.ok ? res.json() : null; })
      .then(function(data){
        var items = data && Array.isArray(data.items) ? data.items : [];
        if(!items.length) return;
        var latestId = newestKnownId;
        var newAuthors = [];
        items.forEach(function(item){
          var postId = Number(item && item.id || 0);
          var authorId = Number(item && item.user_id || 0);
          latestId = Math.max(latestId, postId);
          if(postId > newestKnownId && authorId > 0 && authorId !== viewerId && newAuthors.indexOf(authorId) === -1){
            newAuthors.push(authorId);
          }
        });
        if(!newestKnownId){
          newestKnownId = latestId;
          return;
        }
        if(newAuthors.length){
          paint(newAuthors.slice(0, 3).map(function(id){ return 'avatar.php?u=' + encodeURIComponent(String(id)); }));
          dismissed = false;
          showPillNow();
        }
        newestKnownId = Math.max(newestKnownId, latestId);
      })
      .catch(function(){})
      .then(function(){ checking = false; });
  }
  function watchForPosts(){
    if(typeof MutationObserver === 'undefined') return;
    var root = document.getElementById('mfFeed') || document.querySelector('.ig-feed');
    if(!root) return;
    var observer = new MutationObserver(function(){
      paint();
      if(root.querySelector('.mf-card, .public-post-card')) observer.disconnect();
    });
    observer.observe(root, {childList:true, subtree:true});
  }
  function hidePillNow(){
    dismissed = true;
    pill.classList.remove('is-visible');
    pill.classList.add('is-dismissed');
    pill.hidden = true;
    pill.style.setProperty('display', 'none', 'important');
    pill.style.setProperty('opacity', '0', 'important');
    pill.style.setProperty('visibility', 'hidden', 'important');
    pill.style.setProperty('pointer-events', 'none', 'important');
    pill.setAttribute('aria-hidden', 'true');
  }
  function showPillNow(){
    if(dismissed) return;
    pill.classList.remove('is-dismissed');
    pill.hidden = false;
    pill.style.removeProperty('display');
    pill.style.removeProperty('opacity');
    pill.style.removeProperty('visibility');
    pill.style.removeProperty('pointer-events');
    pill.removeAttribute('aria-hidden');
    pill.classList.add('is-visible');
  }
  function toTop(){
    [document.scrollingElement, document.documentElement, document.body].forEach(function(el){ if(el) el.scrollTop = 0; });
    document.querySelectorAll('.sh-pagebody, .feed-desktop-center, .mf-feed, .ig-feed').forEach(function(el){
      if(typeof el.scrollTo === 'function') el.scrollTo({top:0, behavior:'auto'});
      else el.scrollTop = 0;
    });
  }
  pill.addEventListener('click', function(e){
    try{ e.preventDefault(); e.stopPropagation(); }catch(_e){}
    // Disappear immediately, then jump to top (no full reload on Discover).
    newestKnownId = Math.max(newestKnownId, newestDomId());
    hidePillNow();
    toTop();
    try{
      if(typeof window.refreshList === 'function'){
        window.refreshList(false);
        window.setTimeout(toTop, 120);
        window.setTimeout(toTop, 320);
      }
    }catch(_e){}
    window.requestAnimationFrame(function(){
      hidePillNow();
      toTop();
    });
  });
  document.addEventListener('DOMContentLoaded', function(){
    try{
      if(sessionStorage.getItem('msbPostedPillOpenNewest') === '1'){
        sessionStorage.removeItem('msbPostedPillOpenNewest');
        hidePillNow();
        window.requestAnimationFrame(toTop);
      }
    }catch(e){}
    // Seed newest id from current DOM; do not auto-show on load.
    paint();
    newestKnownId = Math.max(newestKnownId, newestDomId());
    watchForPosts();
  }, {once:true});
  window.setTimeout(function(){
    paint();
    newestKnownId = Math.max(newestKnownId, newestDomId());
  }, 250);
  window.setTimeout(function(){
    paint();
    newestKnownId = Math.max(newestKnownId, newestDomId());
  }, 900);
  window.setTimeout(checkForNewPosts, 2500);
  window.setInterval(checkForNewPosts, 15000);
})();
</script>
