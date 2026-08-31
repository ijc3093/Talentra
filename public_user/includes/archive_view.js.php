<?php
declare(strict_types=1);
?>
<script id="msb-archive-view-js">
(function(){
  if (window.__msbArchiveViewBound) return;
  window.__msbArchiveViewBound = true;

  function bindArchiveRoot(root){
    if (!root || root.__msbArchiveBound) return;
    root.__msbArchiveBound = true;
    var mode = String(root.getAttribute('data-archive-mode') || 'archive');
    var isFav = mode === 'favorites';
    function nextOfClass(el, cls){
      var n = el ? el.nextElementSibling : null;
      while (n) {
        if (n.classList && n.classList.contains(cls)) return n;
        n = n.nextElementSibling;
      }
      return null;
    }
    var host = root.closest ? root.closest('.ig-archive-embed-host') : null;
    var viewer = nextOfClass(root, 'ig-archive-viewer') || (host && host.querySelector('.ig-archive-viewer'));
    var toastEl = nextOfClass(viewer || root, 'ig-archive-toast') || (host && host.querySelector('.ig-archive-toast'));
    var preview = viewer ? viewer.querySelector('.ig-archive-sheet-preview') : null;
    var unBtn = viewer ? viewer.querySelector('.ig-archive-remove-btn') : null;
    var closeBtn = viewer ? viewer.querySelector('.ig-archive-close-btn') : null;
    var nextBtn = root.querySelector('.ig-stories-next');
    var track = root.querySelector('.ig-stories-track');
    var activeId = 0;
    var toastTimer = 0;

    function toast(msg){
      if(!toastEl) return;
      toastEl.textContent = String(msg || '');
      toastEl.classList.add('is-on');
      if(toastTimer) window.clearTimeout(toastTimer);
      toastTimer = window.setTimeout(function(){ toastEl.classList.remove('is-on'); }, 2200);
    }

    function parkToBody(el){
      if(!el || el.parentNode === document.body) return;
      el.__msbHome = { parent: el.parentNode, next: el.nextSibling };
      document.body.appendChild(el);
    }
    function unparkFromBody(el){
      var home = el && el.__msbHome;
      if(!home || !home.parent) return;
      if(home.next && home.next.parentNode === home.parent){
        home.parent.insertBefore(el, home.next);
      } else {
        home.parent.appendChild(el);
      }
      el.__msbHome = null;
    }

    function closeViewer(){
      activeId = 0;
      document.documentElement.classList.remove('ig-archive-modal-open');
      if(viewer){
        viewer.classList.remove('is-open');
        viewer.setAttribute('aria-hidden', 'true');
        unparkFromBody(viewer);
      }
      if(toastEl) unparkFromBody(toastEl);
      if(preview) preview.innerHTML = '';
      if(unBtn) unBtn.disabled = false;
    }

    function openMedia(src, type, caption, postId){
      activeId = Number(postId || 0);
      if(!viewer || !preview || !activeId) return;
      parkToBody(viewer);
      if(toastEl) parkToBody(toastEl);
      document.documentElement.classList.add('ig-archive-modal-open');
      preview.innerHTML = '';
      src = String(src || '');
      type = String(type || 'text');
      caption = String(caption || '');
      if(type === 'video' && src){
        var v = document.createElement('video');
        v.src = src; v.controls = true; v.playsInline = true; v.autoplay = true; v.muted = true;
        preview.appendChild(v);
      } else if(src){
        var img = document.createElement('img');
        img.src = src; img.alt = '';
        preview.appendChild(img);
      } else {
        var fall = document.createElement('div');
        fall.className = 'ig-archive-fallback';
        fall.innerHTML = '<span></span>';
        fall.querySelector('span').textContent = caption || (isFav ? 'Favorite' : 'Story');
        preview.appendChild(fall);
      }
      viewer.classList.add('is-open');
      viewer.setAttribute('aria-hidden', 'false');
    }

    function openStoryCircle(btn){
      if(!btn) return;
      if(window.TTStories && typeof window.TTStories.openFromElement === 'function'){
        if(window.TTStories.openFromElement(btn)) return;
      }
      if(window.TTStories && typeof window.TTStories.openByKey === 'function'){
        var key = String(btn.getAttribute('data-story-key') || '');
        if(key && window.TTStories.openByKey(key)) return;
      }
      var src = String(btn.getAttribute('data-src') || '');
      var type = String(btn.getAttribute('data-type') || 'text');
      var caption = String(btn.getAttribute('data-caption') || '');
      var postId = Number(btn.getAttribute('data-post-id') || 0);
      if(postId > 0 && (src || caption)){
        openMedia(src, type, caption, postId);
        return;
      }
      var raw = btn.getAttribute('data-story-slides') || '[]';
      var slides = [];
      try{ slides = JSON.parse(raw) || []; }catch(e){ slides = []; }
      if(!slides.length) return;
      var first = slides[0] || {};
      openMedia(first.src || '', first.type || 'text', first.caption || '', first.postId || 0);
    }

    function showStoriesEmpty(){
      if(!track) return;
      var emptyIcon = isFav ? 'ion-ios-bookmarks-outline' : 'ion-ios-book-outline';
      var emptyAria = isFav ? 'No favorited stories' : 'No archived stories';
      track.innerHTML = ''
        + '<div class="ig-story-item ig-story-empty" role="status" aria-label="'+emptyAria+'">'
        + '<div class="ig-story-ring ig-story-ring-empty"><span class="ig-archive-empty-icon ig-story-empty-icon" aria-hidden="true"><i class="icon '+emptyIcon+'"></i></span></div>'
        + '</div>';
      track.classList.add('is-empty');
      track.classList.remove('has-create');
      var bar = track.closest('.ig-stories-bar');
      if(bar) bar.classList.add('is-empty');
      if(nextBtn) nextBtn.style.display = 'none';
    }

    function removePostEverywhere(postId){
      postId = String(postId || '');
      root.querySelectorAll('.ig-story-item[data-post-id="'+postId+'"]').forEach(function(el){
        try{ el.remove(); }catch(e){}
      });
      root.querySelectorAll('.ig-archive-grid .ig-archive-tile[data-post-id="'+postId+'"]').forEach(function(el){
        try{ el.remove(); }catch(e){}
      });

      if(track && !track.querySelector('.ig-story-item[data-story-key]')){
        showStoriesEmpty();
      }

      var postList = root.querySelector('.ig-archive-grid');
      if(postList && !postList.querySelector('.ig-archive-tile')){
        var postSection = postList.closest('.ig-archive-section');
        if(postSection) postSection.remove();
      }
    }

    function removeItem(postId){
      postId = Number(postId || 0);
      if(!postId) return;
      if(unBtn) unBtn.disabled = true;
      var body = isFav
        ? new URLSearchParams({ ajax:'save', post_id:String(postId), save_action:'remove' })
        : new URLSearchParams({ ajax:'archive', post_id:String(postId), archived:'0' });
      fetch('feed_api.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        credentials:'same-origin',
        body: body
      }).then(function(r){ return r.json(); }).then(function(res){
        if(!res || res.ok === false){
          if(unBtn) unBtn.disabled = false;
          toast((res && res.error) ? String(res.error) : (isFav ? 'Could not remove favorite.' : 'Could not unarchive.'));
          return;
        }
        removePostEverywhere(postId);
        closeViewer();
        toast(isFav ? 'Removed from Favorites.' : 'Restored to your feed.');
      }).catch(function(){
        if(unBtn) unBtn.disabled = false;
        toast('Network error. Try again.');
      });
    }

    root.addEventListener('click', function(e){
      var storyCircle = e.target && e.target.closest ? e.target.closest('.ig-story-item[data-story-key]') : null;
      if(storyCircle && root.contains(storyCircle)){
        e.preventDefault();
        openStoryCircle(storyCircle);
        return;
      }
      var tile = e.target && e.target.closest ? e.target.closest('.ig-archive-tile, .ig-archive-post-open') : null;
      if(tile && root.contains(tile)){
        e.preventDefault();
        openMedia(
          tile.getAttribute('data-src'),
          tile.getAttribute('data-type'),
          tile.getAttribute('data-caption'),
          tile.getAttribute('data-post-id')
        );
      }
    });

    if(nextBtn && track){
      nextBtn.addEventListener('click', function(){
        track.scrollBy({ left: 140, behavior: 'smooth' });
      });
    }
    if(closeBtn) closeBtn.addEventListener('click', closeViewer);
    if(unBtn) unBtn.addEventListener('click', function(){ removeItem(activeId); });
    if(viewer){
      viewer.addEventListener('click', function(e){
        if(e.target === viewer) closeViewer();
      });
    }
    document.addEventListener('keydown', function(e){
      if(e.key === 'Escape' && viewer && viewer.classList.contains('is-open')) closeViewer();
    });
  }

  function bindAll(){
    document.querySelectorAll('.ig-archive').forEach(bindArchiveRoot);
  }
  bindAll();
  window.MSBArchiveViewBind = bindAll;
})();
</script>
