document.addEventListener('DOMContentLoaded', function () {
  var communityComposer = document.querySelector('.composer.compact');
  if (communityComposer) communityComposer.remove();

  function communityToast(message) {
    var old = document.querySelector('.community-post-toast');
    if (old) old.remove();
    var note = document.createElement('div');
    note.className = 'community-post-toast';
    note.textContent = message;
    document.body.appendChild(note);
    window.setTimeout(function () { note.remove(); }, 1800);
  }
  fetch('community_post_actions_assets.php', { credentials: 'same-origin', cache: 'no-store' })
    .then(function (response) { return response.text(); })
    .then(function (html) {
      var holder = document.createElement('div');
      holder.innerHTML = html;
      Array.from(holder.childNodes).forEach(function (node) {
        if (node.nodeName === 'SCRIPT') {
          var script = document.createElement('script');
          Array.from(node.attributes || []).forEach(function (attr) { script.setAttribute(attr.name, attr.value); });
          script.textContent = node.textContent;
          document.body.appendChild(script);
        } else {
          document.body.appendChild(node);
        }
      });
    })
    .catch(function () {});
  var editModal = document.createElement('div');
  editModal.className = 'community-dashboard-edit-modal';
  editModal.hidden = true;
  editModal.innerHTML = '<section class="community-dashboard-edit-dialog" role="dialog" aria-modal="true" aria-labelledby="communityDashboardEditTitle"><header><h2 id="communityDashboardEditTitle">Edit post</h2><button type="button" aria-label="Close edit post">&times;</button></header><iframe title="Edit community post"></iframe></section>';
  document.body.appendChild(editModal);
  var editModalStyle = document.createElement('style');
  editModalStyle.textContent = '.community-dashboard-edit-modal{position:fixed;inset:0;z-index:120000;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(15,23,42,.48)}.community-dashboard-edit-modal[hidden]{display:none}.community-dashboard-edit-dialog{width:min(560px,calc(100vw - 32px));height:min(92vh,900px);display:grid;grid-template-rows:58px minmax(0,1fr);overflow:hidden;border:1px solid var(--msb-palette-border,rgba(15,23,42,.1));border-radius:16px;background:var(--msb-palette-bg,#fff);box-shadow:0 24px 64px rgba(15,23,42,.28)}.community-dashboard-edit-dialog>header{display:flex;align-items:center;justify-content:space-between;padding:0 20px;border-bottom:1px solid var(--msb-palette-border,#d8dee8)}.community-dashboard-edit-dialog h2{margin:0;font-size:21px}.community-dashboard-edit-dialog header button{width:38px;height:38px;border:0;border-radius:50%;background:rgba(15,23,42,.1);color:var(--msb-palette-text,#0f172a);font-size:28px;line-height:1;cursor:pointer}.community-dashboard-edit-dialog iframe{width:100%;height:100%;border:0;background:var(--msb-palette-bg,#f5f7fb)}body.community-edit-open{overflow:hidden}.community-post-menu.post-card-menu:not([hidden]){display:block!important}body .modal.fade:not(.show){display:none!important;visibility:hidden!important;pointer-events:none!important;background:transparent!important}.community-post-media-stage{position:relative;width:100%;max-width:100%;margin:12px 0 0;display:flex;align-items:flex-start;justify-content:flex-start;overflow:visible;background:transparent}.community-post-media-stage .post-media{display:block;width:auto!important;max-width:100%!important;height:auto!important;max-height:min(56vh,calc(100dvh - 220px))!important;margin:0;object-fit:contain!important;object-position:left center;border-radius:8px!important;background:transparent}.community-post-media-stage.is-landscape .post-media{width:auto!important;max-width:min(100%,720px)!important;margin:0}.community-post-media-stage.is-square .post-media{width:auto!important;max-width:min(100%,620px)!important}.community-post-media-stage.is-portrait .post-media{width:auto!important}.community-post-media-stage.is-loading{width:100%!important;min-height:min(56vh,calc(100dvh - 220px))!important;background:var(--community-loading-media-bg,#f1f1f1);border-radius:8px}@media(max-width:1024px){.community-post-media-stage .post-media{max-height:min(52vh,calc(100dvh - 240px))!important}}@media(max-width:600px){.community-dashboard-edit-modal{padding:0}.community-dashboard-edit-dialog{width:100vw;height:100vh;max-height:none;border:0;border-radius:0}.community-post-media-stage .post-media{width:auto!important;max-width:100%!important;max-height:min(42vh,calc(100dvh - 300px))!important;object-position:center}.community-post-media-stage{justify-content:center}.community-post-media-stage.is-loading{min-height:min(42vh,calc(100dvh - 300px))!important}}';
  document.head.appendChild(editModalStyle);
  var actionModal = document.createElement('div');
  actionModal.className = 'community-action-confirm';
  actionModal.hidden = true;
  actionModal.innerHTML = '<section role="alertdialog" aria-modal="true"><button class="community-action-x" type="button" aria-label="Close">&times;</button><span class="community-action-icon"><i class="fa"></i></span><h2></h2><p></p><div><button class="community-action-cancel" type="button">Cancel</button><button class="community-action-submit" type="button"></button></div></section>';
  document.body.appendChild(actionModal);
  editModalStyle.textContent += '.community-action-confirm{position:fixed;inset:0;z-index:130000;display:grid;place-items:center;padding:16px;background:rgba(15,23,42,.58)}.community-action-confirm[hidden]{display:none}.community-action-confirm>section{position:relative;width:min(440px,calc(100vw - 32px));padding:26px 24px 22px;text-align:center;border:1px solid var(--msb-palette-border,#cbd5e1);border-radius:18px;background:var(--msb-palette-surface,#fff);color:var(--msb-palette-text,#0f172a);box-shadow:0 18px 48px rgba(0,0,0,.28)}.community-action-x{position:absolute;right:12px;top:10px;width:32px;height:32px;padding:0;border:0;border-radius:50%;background:transparent;color:var(--msb-palette-text-muted,#64748b);font-size:25px;line-height:30px;cursor:pointer}.community-action-x:hover{background:var(--msb-palette-surface-2,#eef2f7)}.community-action-icon{width:48px;height:48px;margin:0 auto 12px;border-radius:50%;display:grid;place-items:center;background:var(--msb-palette-action-soft,#e8f1ff);color:var(--msb-palette-action,#2563eb);font-size:16px}.community-action-confirm h2{margin:0 0 8px;font-size:21px;line-height:1.25}.community-action-confirm p{margin:0 auto 20px;max-width:360px;color:var(--msb-palette-text-muted,#64748b);font-size:15px;line-height:1.4}.community-action-confirm section>div{display:grid;grid-template-columns:1fr 1fr;gap:10px}.community-action-confirm section>div button{height:42px;padding:0 16px;border-radius:999px;font-size:15px;font-weight:800;cursor:pointer}.community-action-cancel{border:1px solid var(--msb-palette-border,#cbd5e1);background:var(--msb-palette-surface-2,#eef2f7);color:inherit}.community-action-submit{border:0;background:var(--msb-palette-action,#2563eb);color:#fff}.community-action-submit:disabled{opacity:.65;cursor:wait}.community-action-confirm.is-delete .community-action-icon{background:#fde8ec;color:#e52525}.community-action-confirm.is-delete .community-action-submit{background:#e52525}.community-action-confirm.is-private .community-action-icon{background:#f7e9df;color:#bd5707}.community-action-confirm.is-private .community-action-submit{background:#bd5707}@media(max-width:480px){.community-action-confirm>section{padding:24px 18px 18px}.community-action-confirm section>div{grid-template-columns:1fr}.community-action-confirm section>div button{height:40px}}';
  editModalStyle.textContent += '.community-post-media-stage.has-carousel{display:block;overflow:hidden}.community-post-media-slides{display:grid;width:auto;max-width:calc(100% - 36px);margin-left:18px;margin-right:18px;justify-items:start}.community-post-media-slide{grid-area:1/1;display:flex;align-items:center;justify-content:flex-start;width:100%;opacity:0;pointer-events:none;transition:opacity .35s ease}.community-post-media-slide.is-active{opacity:1;pointer-events:auto;z-index:1}.community-post-media-slide .post-media{display:block;width:auto!important;max-width:100%!important;height:auto!important;max-height:min(56vh,calc(100dvh - 220px))!important;object-fit:contain!important;object-position:center center;border-radius:8px!important;background:transparent}.community-post-media-slide.is-landscape .post-media{width:auto!important;max-width:min(100%,720px)!important}.community-post-media-slide.is-square .post-media{width:auto!important;max-width:min(100%,620px)!important}.community-media-nav{position:absolute;top:50%;z-index:4;transform:translateY(-50%);width:28px;height:28px;padding:0;border:0;border-radius:50%;display:grid;place-items:center;background:rgba(255,255,255,.88);color:#1f2937;box-shadow:0 4px 14px rgba(0,0,0,.2);cursor:pointer}.community-media-nav.prev{left:12px}.community-media-nav.next{right:12px}.community-media-dots{position:absolute;left:50%;bottom:12px;z-index:4;transform:translateX(-50%);display:flex;gap:5px}.community-media-dots button{width:6px;height:6px;min-width:6px;padding:0;border:0;border-radius:50%;background:rgba(255,255,255,.62);box-shadow:0 0 0 1px rgba(0,0,0,.14)}.community-media-dots button.is-active{background:var(--msb-palette-action,#3897f0)}@media(max-width:600px){.community-post-media-slide{justify-content:flex-start}.community-post-media-slide .post-media{width:auto!important;max-width:100%!important;max-height:min(42vh,calc(100dvh - 300px))!important;object-position:center}}';
  editModalStyle.textContent += '.community-post-media-slides{width:auto!important;max-width:calc(100% - 36px)!important;margin:0 18px!important;background:transparent!important;border-radius:0!important;overflow:visible!important;clip-path:none!important;-webkit-clip-path:none!important}.community-post-media-slide{background:transparent!important;border-radius:0!important;overflow:visible!important;justify-content:flex-start!important}.community-post-media-clip{display:inline-block!important;width:fit-content!important;max-width:100%!important;line-height:0!important;border-radius:8px!important;overflow:hidden!important;clip-path:inset(0 round 8px)!important;-webkit-clip-path:inset(0 round 8px)!important;mask-image:none!important;-webkit-mask-image:none!important;background:transparent!important}.community-post-media-slide.is-landscape .community-post-media-clip,.community-post-media-slide.is-portrait .community-post-media-clip,.community-post-media-slide.is-square .community-post-media-clip{width:fit-content!important}.community-post-media-stage.is-square .community-post-media-slides{width:auto;max-width:min(calc(100% - 36px),620px)}.community-post-media-slide video.post-media,.community-post-media-slide img.post-media,.community-post-media-stage .post-media{display:block!important;width:auto!important;max-width:min(100%,720px)!important;height:auto!important;max-height:min(56vh,calc(100dvh - 220px))!important;border-radius:8px!important;overflow:hidden!important;clip-path:inset(0 round 8px)!important;-webkit-clip-path:inset(0 round 8px)!important;mask-image:none!important;-webkit-mask-image:none!important;background:transparent!important;object-fit:contain!important}.community-post-media-slide video.post-media,.community-post-media-slide.is-landscape video.post-media,.community-post-media-slide.is-portrait video.post-media{width:auto!important}.community-post-media-slide.is-square .community-post-media-clip .post-media,.community-post-media-slide.is-square .post-media{width:auto!important;max-width:min(100%,620px)!important;height:auto!important;max-height:min(56vh,calc(100dvh - 220px))!important;object-fit:contain!important}';;;;
  var pendingCommunityAction = null;
  function closeCommunityAction() { actionModal.hidden = true; pendingCommunityAction = null; }
  function openCommunityAction(action, post, communityPostId, csrf) {
    var copy = {
      private: ['fa-lock','Make this private?','Only you will see it. It will move to your Gallery → Private.','Private'],
      archive: ['fa-archive','Archive this post?','It will be hidden from the community. Find it under Settings → Archived posts.','Archive'],
      delete: ['fa-trash','Delete this post?','This action cannot be undone. The community post will be permanently removed.','Delete']
    }[action];
    if (!copy) return;
    pendingCommunityAction = { action: action, post: post, id: communityPostId, csrf: csrf };
    actionModal.className = 'community-action-confirm is-' + action;
    actionModal.querySelector('.community-action-icon i').className = 'fa ' + copy[0];
    actionModal.querySelector('h2').textContent = copy[1];
    actionModal.querySelector('p').textContent = copy[2];
    actionModal.querySelector('.community-action-submit').textContent = copy[3];
    actionModal.hidden = false;
  }
  actionModal.querySelector('.community-action-x').addEventListener('click', closeCommunityAction);
  actionModal.querySelector('.community-action-cancel').addEventListener('click', closeCommunityAction);
  actionModal.addEventListener('click', function (event) { if (event.target === actionModal) closeCommunityAction(); });
  actionModal.querySelector('.community-action-submit').addEventListener('click', function () {
    if (!pendingCommunityAction) return;
    var current = pendingCommunityAction;
    this.disabled = true;
    fetch('ajax/community_post_action.php', { method: 'POST', credentials: 'same-origin', headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body: new URLSearchParams({action:current.action,community_post_id:String(current.id),csrf:current.csrf}) })
      .then(function (response) { return response.json(); })
      .then(function (result) {
        if (!result || !result.ok) throw new Error((result && result.error) || 'Action failed.');
        closeCommunityAction();
        current.post.remove();
        communityToast(result.message || 'Post updated.');
      })
      .catch(function (error) { window.alert(error.message || 'Could not update this post.'); })
      .finally(function () { actionModal.querySelector('.community-action-submit').disabled = false; });
  });
  var editFrame = editModal.querySelector('iframe');
  function closeDashboardEdit() {
    editModal.hidden = true;
    editFrame.src = 'about:blank';
    document.body.classList.remove('community-edit-open');
  }
  function openDashboardEdit(url) {
    var root = document.documentElement;
    var palette = window.getComputedStyle(root);
    var modalUrl = new URL(url, window.location.href);
    var appearance = root.getAttribute('data-msb-appearance');
    var paletteBg = palette.getPropertyValue('--msb-palette-bg').trim();
    var paletteAction = palette.getPropertyValue('--msb-palette-action').trim();
    modalUrl.searchParams.set('modal', '1');
    if (appearance) modalUrl.searchParams.set('appearance', appearance);
    if (/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test(paletteBg)) modalUrl.searchParams.set('palette_bg', paletteBg);
    if (/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test(paletteAction)) modalUrl.searchParams.set('palette_action', paletteAction);
    editFrame.src = modalUrl.href;
    editModal.hidden = false;
    document.body.classList.add('community-edit-open');
  }
  var sharedPostModal = window.MSBCreatePostModal;
  window.MSBCreatePostModal = {
    open: sharedPostModal && typeof sharedPostModal.open === 'function' ? sharedPostModal.open : openDashboardEdit,
    close: function () {
      if (!editModal.hidden) closeDashboardEdit();
      else if (sharedPostModal && typeof sharedPostModal.close === 'function') sharedPostModal.close();
    }
  };
  editModal.querySelector('header button').addEventListener('click', closeDashboardEdit);
  editModal.addEventListener('click', function (event) { if (event.target === editModal) closeDashboardEdit(); });
  document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && !editModal.hidden) closeDashboardEdit(); });
  window.addEventListener('message', function (event) {
    if (event.source !== editFrame.contentWindow || !event.data) return;
    if (event.data.type === 'msb-create-post-done') {
      closeDashboardEdit();
      window.location.reload();
    }
  });

  document.querySelectorAll('article.post').forEach(function (post, postIndex) {
    var publicPostId = Number((window.__communityPostPublicIds || [])[postIndex] || 0);
    if (publicPostId > 0) {
      post.classList.add('public-post-card');
      post.setAttribute('data-post-id', String(publicPostId));
      post.setAttribute('data-is-owner', post.querySelector('details') ? '1' : '0');
      post.setAttribute('data-visibility', 'public');
      post.setAttribute('data-my-reaction', '');
      post.setAttribute('data-love-count', '0');
      post.setAttribute('data-like-count', '0');
      post.setAttribute('data-reaction-count', '0');
      post.setAttribute('data-comment-count', '0');
      post.setAttribute('data-share-count', '0');
      post.setAttribute('data-save-count', '0');
      post.setAttribute('data-is-shared', '0');
      post.setAttribute('data-is-saved', '0');
    }
    if (!post.id) post.id = 'community-post-' + (postIndex + 1);
    var attachmentMap = window.__communityPostAttachments || {};
    var attachments = attachmentMap[String(publicPostId)] || attachmentMap[publicPostId] || [];
    if (attachments.length) {
      post.classList.add('community-card-media-loading');
      var loadingCard = post.querySelector(':scope > .community-loading-card-skeleton');
      if (!loadingCard) {
        loadingCard = document.createElement('div');
        loadingCard.className = 'community-loading-card-skeleton';
        loadingCard.setAttribute('aria-hidden', 'true');
        loadingCard.innerHTML = '<div class="community-loading-card-head"><span class="community-loading-card-avatar"></span><span class="community-loading-card-copy"><i></i><i></i></span><span class="community-loading-card-fries"><i></i><i></i><i></i></span></div><div class="community-loading-card-media"></div><div class="community-loading-card-actions"><i></i><i></i><i></i><i></i></div>';
        post.insertBefore(loadingCard, post.firstChild);
      }
      var loadingCardMedia = loadingCard.querySelector('.community-loading-card-media');
      var oldMedia = post.querySelector(':scope > .post-media');
      var mediaAnchor = oldMedia ? oldMedia.nextSibling : null;
      var attachmentStage = document.createElement('div');
      var loadingShape = String((attachments[0] && attachments[0].shape) || 'landscape').toLowerCase();
      if (['portrait', 'landscape', 'square'].indexOf(loadingShape) === -1) loadingShape = 'landscape';
      attachmentStage.className = 'community-post-media-stage is-loading is-' + loadingShape + (attachments.length > 1 ? ' has-carousel' : '');
      var loadingWidth = Number(attachments[0] && attachments[0].width || 0);
      var loadingHeight = Number(attachments[0] && attachments[0].height || 0);
      function sizeLoadingCard(width, height, shape) {
        width = Number(width || 0);
        height = Number(height || 0);
        if (!loadingCardMedia || !width || !height) return;
        var maxWidth = shape === 'is-square' || shape === 'square' ? 620 : 720;
        var portrait = shape === 'is-portrait' || shape === 'portrait';
        var heightRatio = window.innerWidth <= 600 ? (portrait ? .56 : .42) : (portrait ? .72 : .56);
        var heightOffset = window.innerWidth <= 600 ? (portrait ? 210 : 300) : (portrait ? 120 : 220);
        var maxHeight = Math.max(1, Math.min(window.innerHeight * heightRatio, window.innerHeight - heightOffset));
        var availableWidth = Math.max(1, post.clientWidth - 36);
        var scale = Math.min(1, maxWidth / width, maxHeight / height, availableWidth / width);
        loadingCardMedia.style.setProperty('width', Math.max(1, Math.round(width * scale)) + 'px', 'important');
        loadingCardMedia.style.setProperty('aspect-ratio', width + ' / ' + height, 'important');
      }
      if (loadingWidth > 0 && loadingHeight > 0) sizeLoadingCard(loadingWidth, loadingHeight, loadingShape);
      if (loadingWidth > 0 && loadingHeight > 0) attachmentStage.style.setProperty('aspect-ratio', loadingWidth + ' / ' + loadingHeight, 'important');
      attachmentStage.setAttribute('data-index', '0');
      attachmentStage.style.setProperty('display', 'none', 'important');
      var attachmentReady = false;
      function releaseLoadingCard() {
        attachmentStage.classList.remove('is-loading');
        post.classList.remove('community-card-media-loading');
        if (loadingCard && loadingCard.isConnected) loadingCard.remove();
      }
      function revealAttachmentStage() {
        if (attachmentReady) return;
        attachmentReady = true;
        if (oldMedia && oldMedia.isConnected) oldMedia.remove();
        attachmentStage.style.removeProperty('display');
      }
      var attachmentSlides = document.createElement('div');
      attachmentSlides.className = 'community-post-media-slides';
      attachments.forEach(function (attachment, mediaIndex) {
        var slide = document.createElement('div');
        slide.className = 'community-post-media-slide' + (mediaIndex === 0 ? ' is-active' : '');
        slide.setAttribute('data-slide-index', String(mediaIndex));
        var media;
        if (String(attachment.type || '').toLowerCase() === 'video') {
          media = document.createElement('video');
          media.controls = false;
          media.playsInline = true;
          media.preload = mediaIndex === 0 ? 'auto' : 'metadata';
          if (attachment.thumb_path) media.poster = attachment.thumb_path;
          media.setAttribute('aria-label', 'Community post video. Click to play or pause.');
          media.addEventListener('click', function () {
            if (media.paused) media.play().catch(function () {});
            else media.pause();
          });
        } else {
          media = document.createElement('img');
          media.alt = 'Community post media';
        }
        media.className = 'post-media';
        media.loading = 'eager';
        if (media.tagName === 'IMG') media.fetchPriority = mediaIndex === 0 ? 'high' : 'auto';
        media.style.setProperty('display', 'block', 'important');
        media.style.setProperty('width', 'auto', 'important');
        media.style.setProperty('max-width', 'min(100%, 720px)', 'important');
        media.style.setProperty('height', 'auto', 'important');
        media.style.setProperty('max-height', 'min(56vh, calc(100dvh - 220px))', 'important');
        media.style.setProperty('object-fit', 'contain', 'important');
        media.style.setProperty('border-radius', '8px', 'important');
        media.style.setProperty('clip-path', 'inset(0 round 8px)', 'important');
        media.style.setProperty('-webkit-clip-path', 'inset(0 round 8px)', 'important');
        media.src = attachment.file_path;
        var mediaClip = document.createElement('div');
        mediaClip.className = 'community-post-media-clip';
        mediaClip.style.setProperty('border-radius', '8px', 'important');
        mediaClip.style.setProperty('overflow', 'hidden', 'important');
        mediaClip.style.setProperty('clip-path', 'inset(0 round 8px)', 'important');
        mediaClip.style.setProperty('-webkit-clip-path', 'inset(0 round 8px)', 'important');
        mediaClip.style.setProperty('display', 'inline-block', 'important');
        mediaClip.style.setProperty('width', 'fit-content', 'important');
        mediaClip.style.setProperty('max-width', '100%', 'important');
        mediaClip.style.setProperty('background', 'transparent', 'important');
        mediaClip.style.setProperty('line-height', '0', 'important');
        mediaClip.style.setProperty('margin-top', '-10px');
        function applyShape(mediaReady) {
          var width = Number(media.naturalWidth || media.videoWidth || 0);
          var height = Number(media.naturalHeight || media.videoHeight || 0);
          if (!width || !height) return;
          var shape = height > width * 1.1 ? 'is-portrait' : (width > height * 1.15 ? 'is-landscape' : 'is-square');
          slide.classList.remove('is-portrait', 'is-landscape', 'is-square');
          slide.classList.add(shape);
          if (attachments.length === 1) {
            attachmentStage.classList.remove('is-portrait', 'is-landscape', 'is-square');
            attachmentStage.classList.add(shape);
          }
          var exactMaxWidth = shape === 'is-square' ? 620 : 720;
          var portraitMedia = shape === 'is-portrait';
          var exactHeightRatio = window.innerWidth <= 600 ? (portraitMedia ? .56 : .42) : (portraitMedia ? .72 : .56);
          var exactHeightOffset = window.innerWidth <= 600 ? (portraitMedia ? 210 : 300) : (portraitMedia ? 120 : 220);
          var exactMaxHeight = Math.max(1, Math.min(window.innerHeight * exactHeightRatio, window.innerHeight - exactHeightOffset));
          var exactAvailableWidth = Math.max(1, post.clientWidth - 36);
          var exactScale = Math.min(1, exactMaxWidth / width, exactMaxHeight / height, exactAvailableWidth / width);
          attachmentStage.style.setProperty('width', Math.max(1, Math.round(width * exactScale)) + 'px', 'important');
          attachmentStage.style.setProperty('aspect-ratio', width + ' / ' + height, 'important');
          sizeLoadingCard(width, height, shape);
          media.style.setProperty('max-width', shape === 'is-square' ? 'min(100%, 620px)' : 'min(100%, 720px)', 'important');
          media.style.setProperty('max-height', shape === 'is-portrait' ? 'min(72vh, calc(100dvh - 120px))' : 'min(56vh, calc(100dvh - 220px))', 'important');
          revealAttachmentStage();
          if (mediaReady === false) return;
          attachmentStage.style.removeProperty('width');
          attachmentStage.style.removeProperty('aspect-ratio');
          releaseLoadingCard();
        }
        media.addEventListener('error', function () {
          slide.remove();
          if (!attachmentSlides.querySelector('.community-post-media-slide')) {
            attachmentStage.remove();
            post.classList.remove('community-card-media-loading');
            if (loadingCard && loadingCard.isConnected) loadingCard.remove();
          }
        }, { once: true });
        if (media.tagName === 'VIDEO') {
          /* Metadata gives us the exact video dimensions and a stable native
             media surface. Reveal then instead of holding the whole card until
             the browser has decoded a playable frame (which can be slow for
             large or non-fast-start MP4 files). */
          media.addEventListener('loadedmetadata', function () { applyShape(true); }, { once: true });
          var revealVideoFrame = function () { applyShape(true); };
          media.addEventListener('loadeddata', function () {
            if (typeof media.requestVideoFrameCallback === 'function') {
              try { media.requestVideoFrameCallback(revealVideoFrame); } catch (_frameError) {}
            }
            /* requestVideoFrameCallback can wait for playback forever. A
               decoded loadeddata frame is already safe to reveal. */
            window.requestAnimationFrame(function () { window.requestAnimationFrame(revealVideoFrame); });
            window.setTimeout(revealVideoFrame, 350);
          }, { once: true });
          media.addEventListener('canplay', revealVideoFrame, { once: true });
          media.addEventListener('canplaythrough', revealVideoFrame, { once: true });
        } else {
          media.addEventListener('load', function () { applyShape(true); }, { once: true });
        }
        mediaClip.appendChild(media);
        slide.appendChild(mediaClip);
        attachmentSlides.appendChild(slide);
        if (media.tagName === 'VIDEO' && media.readyState >= 2 && media.videoWidth) applyShape(true);
        else if (media.tagName === 'VIDEO' && media.readyState >= 1 && media.videoWidth) applyShape(true);
        else if (media.tagName === 'IMG' && media.complete && media.naturalWidth) applyShape(true);
        else if (media.tagName === 'IMG' && media.complete && !media.naturalWidth) {
          slide.remove();
          if (!attachmentSlides.querySelector('.community-post-media-slide')) {
            attachmentStage.remove();
            post.classList.remove('community-card-media-loading');
            if (loadingCard && loadingCard.isConnected) loadingCard.remove();
          }
        }
      });
      attachmentStage.appendChild(attachmentSlides);
      if (attachments.length > 1) {
        ['prev', 'next'].forEach(function (direction) {
          var nav = document.createElement('button');
          nav.type = 'button';
          nav.className = 'community-media-nav ' + direction;
          nav.setAttribute('aria-label', direction === 'prev' ? 'Previous media' : 'Next media');
          nav.innerHTML = '<i class="fa fa-chevron-' + (direction === 'prev' ? 'left' : 'right') + '"></i>';
          attachmentStage.appendChild(nav);
        });
        var dots = document.createElement('div');
        dots.className = 'community-media-dots';
        attachments.forEach(function (_, dotIndex) {
          var dot = document.createElement('button');
          dot.type = 'button';
          dot.setAttribute('data-index', String(dotIndex));
          dot.setAttribute('aria-label', 'Go to media ' + (dotIndex + 1));
          if (dotIndex === 0) dot.className = 'is-active';
          dots.appendChild(dot);
        });
        attachmentStage.appendChild(dots);
        function showAttachment(index) {
          var slides = attachmentStage.querySelectorAll('.community-post-media-slide');
          index = (index + slides.length) % slides.length;
          attachmentStage.setAttribute('data-index', String(index));
          slides.forEach(function (slide, i) {
            slide.classList.toggle('is-active', i === index);
            var video = slide.querySelector('video');
            if (video && i !== index) video.pause();
          });
          attachmentStage.querySelectorAll('.community-media-dots button').forEach(function (dot, i) { dot.classList.toggle('is-active', i === index); });
        }
        attachmentStage.addEventListener('click', function (event) {
          var nav = event.target.closest('.community-media-nav');
          var dot = event.target.closest('.community-media-dots button');
          var current = Number(attachmentStage.getAttribute('data-index') || 0);
          if (nav) showAttachment(current + (nav.classList.contains('next') ? 1 : -1));
          if (dot) showAttachment(Number(dot.getAttribute('data-index') || 0));
        });
      }
      var firstActions = post.querySelector('.community-post-engagement, details');
      post.insertBefore(attachmentStage, mediaAnchor && mediaAnchor.parentNode === post ? mediaAnchor : (firstActions || null));
      if (loadingWidth > 0 && loadingHeight > 0 && attachmentStage.classList.contains('is-loading')) {
        var loadingMaxWidth = loadingShape === 'square' ? 620 : 720;
        var portraitLoading = loadingShape === 'portrait';
        var loadingHeightRatio = window.innerWidth <= 600 ? (portraitLoading ? .56 : .42) : (portraitLoading ? .72 : .56);
        var loadingHeightOffset = window.innerWidth <= 600 ? (portraitLoading ? 210 : 300) : (portraitLoading ? 120 : 220);
        var loadingMaxHeight = Math.max(1, Math.min(window.innerHeight * loadingHeightRatio, window.innerHeight - loadingHeightOffset));
        var loadingAvailableWidth = Math.max(1, post.clientWidth - 36);
        var loadingScale = Math.min(1, loadingMaxWidth / loadingWidth, loadingMaxHeight / loadingHeight, loadingAvailableWidth / loadingWidth);
        attachmentStage.style.setProperty('width', Math.max(1, Math.round(loadingWidth * loadingScale)) + 'px', 'important');
      }
      revealAttachmentStage();
      // A video element is valid before metadata arrives. Reveal it immediately
      // so a large MP4 never leaves the server-rendered <img> error visible.
      if (String((attachments[0] && attachments[0].type) || '').toLowerCase() === 'video') {
        revealAttachmentStage();
      }
    }
    var postMedia = post.querySelector(':scope > .post-media');
    if (postMedia && !postMedia.closest('.community-post-media-stage')) {
      var mediaStage = document.createElement('div');
      mediaStage.className = 'community-post-media-stage';
      postMedia.style.setProperty('display', 'block', 'important');
      postMedia.style.setProperty('width', 'auto', 'important');
      postMedia.style.setProperty('max-width', 'min(100%, 720px)', 'important');
      postMedia.style.setProperty('height', 'auto', 'important');
      postMedia.style.setProperty('max-height', 'min(56vh, calc(100dvh - 220px))', 'important');
      postMedia.style.setProperty('object-fit', 'contain', 'important');
      postMedia.parentNode.insertBefore(mediaStage, postMedia);
      mediaStage.appendChild(postMedia);
      function sizeCommunityMedia() {
        var width = Number(postMedia.naturalWidth || postMedia.videoWidth || 0);
        var height = Number(postMedia.naturalHeight || postMedia.videoHeight || 0);
        if (!width || !height) return;
        mediaStage.classList.remove('is-loading', 'is-portrait', 'is-landscape', 'is-square');
        mediaStage.classList.add(height > width * 1.1 ? 'is-portrait' : (width > height * 1.15 ? 'is-landscape' : 'is-square'));
        if (mediaStage.classList.contains('is-square')) {
          postMedia.style.setProperty('max-width', 'min(100%, 620px)', 'important');
        }
      }
      if (postMedia.complete && postMedia.naturalWidth) sizeCommunityMedia();
      else postMedia.addEventListener('load', sizeCommunityMedia, { once: true });
      postMedia.addEventListener('error', function () { mediaStage.remove(); }, { once: true });
    }
    function toast(message) {
      var old = document.querySelector('.community-post-toast');
      if (old) old.remove();
      var note = document.createElement('div');
      note.className = 'community-post-toast';
      note.textContent = message;
      document.body.appendChild(note);
      window.setTimeout(function () { note.remove(); }, 1600);
    }
    var head = post.querySelector('.post-head');
    if (head && !head.querySelector('.community-post-avatar')) {
      var name = (head.querySelector('strong') || {}).textContent || 'Member';
      var initials = name.trim().split(/\s+/).slice(0, 2).map(function (part) {
        return part.charAt(0).toUpperCase();
      }).join('') || 'M';
      var avatar = document.createElement('span');
      avatar.className = 'community-post-avatar';
      avatar.textContent = initials;
      head.insertBefore(avatar, head.firstChild);
      var identity = document.createElement('span');
      identity.className = 'community-post-identity';
      while (avatar.nextSibling) identity.appendChild(avatar.nextSibling);
      head.appendChild(identity);
      var author = identity.querySelector('strong');
      var time = identity.querySelector('.muted');
      if (author) {
        var nameRow = document.createElement('span');
        nameRow.className = 'community-post-name-row';
        author.parentNode.insertBefore(nameRow, author);
        nameRow.appendChild(author);
        if (time) {
          time.className = 'community-post-meta';
          time.innerHTML = '<i class="fa fa-users" aria-hidden="true"></i><span>' + time.textContent + '</span><i class="fa fa-globe" aria-hidden="true"></i>';
          nameRow.appendChild(time);
        }
      } else if (time) {
        time.className = 'community-post-meta';
        time.innerHTML = '<i class="fa fa-users" aria-hidden="true"></i><span>' + time.textContent + '</span><i class="fa fa-globe" aria-hidden="true"></i>';
      }
      var badge = document.createElement('span');
      badge.className = 'community-post-member-badge';
      badge.textContent = 'Member';
      var menu = document.createElement('button');
      menu.type = 'button';
      menu.className = 'community-post-fries';
      menu.setAttribute('aria-label', 'Post options');
      menu.innerHTML = '<span class="pcm-fries-icon" aria-hidden="true"><span class="pcm-fries-bar"></span><span class="pcm-fries-bar pcm-fries-bar--short"></span><span class="pcm-fries-bar"></span><span class="pcm-fries-bar pcm-fries-bar--short"></span></span>';
      head.appendChild(badge);
      head.appendChild(menu);
      var dropdown = document.createElement('div');
      dropdown.className = 'community-post-menu post-card-menu post-card-menu-wrap';
      dropdown.setAttribute('data-post-id', String(publicPostId));
      dropdown.setAttribute('data-is-owner', editor ? '1' : '0');
      dropdown.setAttribute('data-peer-id', editor ? String(window.ME_ID || 0) : '0');
      dropdown.hidden = true;
      dropdown.innerHTML = '<div class="community-post-menu-group">'
        + '<button type="button" data-action="view"><i class="fa fa-expand"></i><span>View the post</span></button>'
        + '</div><div class="community-post-menu-group owner-actions">'
        + '<button type="button" data-action="edit"><i class="fa fa-pencil-square-o"></i><span>Edit</span></button>'
        + '<button type="button" data-action="tag"><i class="fa fa-at"></i><span>Tag</span></button>'
        + '<button type="button" data-action="mention"><i class="fa fa-bullhorn"></i><span>Mention</span></button>'
        + '<button type="button" data-action="private"><i class="fa fa-lock"></i><span>Private</span></button>'
        + '<button type="button" data-action="archive"><i class="fa fa-archive"></i><span>Archive</span></button>'
        + '<button type="button" class="danger" data-action="delete"><i class="fa fa-trash"></i><span>Delete</span></button>'
        + '</div><div class="community-post-menu-group">'
        + '<button type="button" data-action="repost"><i class="fa fa-retweet"></i><span>Repost</span></button>'
        + '<button type="button" data-action="favorite"><i class="fa fa-bookmark-o"></i><span>Favorite</span></button>'
        + '<button type="button" data-action="share"><i class="fa fa-share"></i><span>Share</span></button>'
        + '<button type="button" data-action="copy"><i class="fa fa-link"></i><span>Copy link</span></button>'
        + '</div>';
      head.appendChild(dropdown);
      var editor = post.querySelector('details');
      var communityPostIdInput = editor ? editor.querySelector('input[name="post_id"]') : null;
      var communityCsrfInput = editor ? editor.querySelector('input[name="csrf"]') : null;
      var dashboardEditUrl = communityPostIdInput
        ? 'dashboard.php?community_post=' + encodeURIComponent(communityPostIdInput.value)
        : '';
      if (!editor) dropdown.querySelector('.owner-actions').hidden = true;
      var sharedClasses = {
        tag: 'pcm-tag', mention: 'pcm-mention', repost: 'pcm-post',
        favorite: 'pcm-bookmark', share: 'pcm-share', copy: 'pcm-copy-link'
      };
      Object.keys(sharedClasses).forEach(function (actionName) {
        var actionButton = dropdown.querySelector('[data-action="' + actionName + '"]');
        if (!actionButton || publicPostId <= 0) return;
        actionButton.classList.add(sharedClasses[actionName]);
        actionButton.setAttribute('data-post-id', String(publicPostId));
        if (actionName === 'favorite') actionButton.setAttribute('data-saved', '0');
      });
      menu.addEventListener('click', function (event) {
        event.stopPropagation();
        document.querySelectorAll('.community-post-menu').forEach(function (other) {
          if (other !== dropdown) other.hidden = true;
        });
        dropdown.hidden = !dropdown.hidden;
      });
      dropdown.addEventListener('click', function (event) {
        var item = event.target.closest('button[data-action]');
        if (!item) return;
        var action = item.getAttribute('data-action');
        dropdown.hidden = true;
        if (action === 'view') post.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (action === 'edit' && dashboardEditUrl) {
          openDashboardEdit(dashboardEditUrl);
        }
        if ((action === 'private' || action === 'archive' || action === 'delete') && communityPostIdInput && communityCsrfInput) {
          event.stopPropagation();
          openCommunityAction(action, post, Number(communityPostIdInput.value || 0), communityCsrfInput.value);
          return;
        }
        if (sharedClasses[action] && publicPostId > 0) {
          // Prefer the Discover action buttons (API-backed) for favorite/share.
          if (action === 'favorite') {
            var favBtn = post.querySelector('.js-save-post');
            if (favBtn) favBtn.click();
            return;
          }
          if (action === 'share') {
            var shBtn = post.querySelector('.js-share-post');
            if (shBtn) shBtn.click();
            return;
          }
          if (action === 'copy' && publicPostId > 0) {
            var copyUrl = location.origin + location.pathname.replace(/[^/]+$/, '') + 'post_view.php?id=' + publicPostId;
            if (navigator.clipboard) navigator.clipboard.writeText(copyUrl).then(function () { toast('Link copied'); });
            else toast('Copy link: ' + copyUrl);
            return;
          }
        }
        if ((action === 'tag' || action === 'mention')) {
          var commentButton = post.querySelector('.community-post-engagement .comment, .js-open-comments');
          if (commentButton) commentButton.click();
          var commentInput = post.querySelector('.community-comment-compose input');
          if (commentInput) { commentInput.value = '@'; commentInput.focus(); }
        }
        if (action === 'delete' && editor) {
          var deleteButton = editor.querySelector('button[value="delete_post"]');
          if (deleteButton) deleteButton.click();
        }
        if (action === 'favorite') {
          var saveButton = post.querySelector('.community-post-engagement .save, .js-save-post');
          if (saveButton) saveButton.click();
        }
        if (action === 'private') toast('Privacy controls opened');
        if (action === 'archive') toast('Post archived');
        if (action === 'repost') toast('Repost options opened');
        if (action === 'share') {
          var shareBarBtn = post.querySelector('.js-share-post');
          if (shareBarBtn) shareBarBtn.click();
          else if (navigator.share) navigator.share({ title: document.title, url: location.href.split('#')[0] + '#' + post.id }).catch(function () {});
          else toast('Share link ready');
        }
        if (action === 'copy') {
          var link = location.href.split('#')[0] + '#' + post.id;
          if (navigator.clipboard) navigator.clipboard.writeText(link).then(function () { toast('Link copied'); });
          else toast('Copy link: ' + link);
        }
      });
    }
    if (!post.querySelector('.community-post-engagement')) {
      var bar = document.createElement('div');
      bar.className = 'community-post-engagement standard-text-actions';
      var pidAttr = publicPostId > 0 ? (' data-post-id="' + publicPostId + '"') : '';
      // Keep Discover action-bar markup identical; only add data-post-id for API wiring.
      bar.innerHTML = ''
        + '<div class="standard-text-left"><div class="standard-text-row">'
        +   '<span class="msb-react-cluster">'
        +     '<button type="button" class="standard-text-btn react js-react-love"' + pidAttr + ' aria-label="Love"><i class="msb-pact msb-pact-heart" aria-hidden="true"></i></button>'
        +     '<span class="action-count js-love-count">0</span>'
        +   '</span>'
        +   '<button type="button" class="standard-text-btn comment js-open-comments"' + pidAttr + ' aria-label="Comment">'
        +     '<i class="msb-pact msb-pact-comment" aria-hidden="true"></i>'
        +     '<span class="action-count js-comment-count-inline">0</span>'
        +   '</button>'
        +   '<span class="msb-react-cluster">'
        +     '<button type="button" class="standard-text-btn share js-share-post"' + pidAttr + ' aria-label="Share"><i class="msb-pact msb-pact-share" aria-hidden="true"></i></button>'
        +     '<span class="action-count js-share-count">0</span>'
        +   '</span>'
        + '</div></div>'
        + '<div class="standard-text-right">'
        +   '<span class="msb-react-cluster">'
        +     '<button type="button" class="standard-text-btn save js-save-post"' + pidAttr + ' aria-label="Save"><i class="msb-pact msb-pact-bookmark" aria-hidden="true"></i></button>'
        +     '<span class="action-count js-save-count">0</span>'
        +   '</span>'
        + '</div>';
      post.appendChild(bar);
      var comments = document.createElement('div');
      comments.className = 'community-post-comments';
      comments.innerHTML = '<form class="community-comment-compose"><span class="community-comment-avatar">' + initials + '</span><input type="text" aria-label="Write a comment" placeholder="Write a comment…"><button type="submit" aria-label="Post comment"><i class="fa fa-paper-plane"></i></button></form>';
      post.appendChild(comments);

      if (publicPostId > 0) {
        // API-backed: community_post_engagement.js owns love/share/save; leftbar owns comments.
        comments.querySelector('form').addEventListener('submit', function (event) {
          event.preventDefault();
          var input = this.querySelector('input');
          var body = (input.value || '').trim();
          if (!body) return;
          fetch('feed_api.php?ajax=comment', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: new URLSearchParams({ post_id: String(publicPostId), comment_text: body })
          }).then(function (r) { return r.json(); }).then(function (res) {
            if (!res || !res.ok) throw new Error((res && res.error) || 'Comment failed');
            input.value = '';
            var countEl = bar.querySelector('.js-comment-count-inline');
            var next = Number((post.getAttribute('data-comment-count') || '0')) + 1;
            post.setAttribute('data-comment-count', String(next));
            if (countEl) countEl.textContent = String(next);
            toast('Comment added');
          }).catch(function () { toast('Unable to add comment.'); });
        });
        if (window.MSBCommunityEngagement && typeof window.MSBCommunityEngagement.hydrateCard === 'function') {
          window.MSBCommunityEngagement.hydrateCard(post);
        }
      } else {
        // Local stubs only when no public bridge id exists yet.
        var loveBtn = bar.querySelector('.react');
        var loveCount = bar.querySelector('.js-love-count');
        loveBtn.addEventListener('click', function () {
          var active = this.classList.toggle('is-loved');
          var icon = this.querySelector('.msb-pact-heart');
          if (icon) icon.classList.toggle('is-active', active);
          if (loveCount) loveCount.textContent = active ? '1' : '0';
        });
        bar.querySelector('.comment').addEventListener('click', function () {
          comments.classList.toggle('is-open');
          if (comments.classList.contains('is-open')) comments.querySelector('input').focus();
        });
        bar.querySelector('.share').addEventListener('click', function () { toast('Share options opened'); });
        var saveBtn = bar.querySelector('.save');
        var saveCount = bar.querySelector('.js-save-count');
        saveBtn.addEventListener('click', function () {
          var active = this.classList.toggle('is-active');
          var icon = this.querySelector('.msb-pact-bookmark');
          if (icon) icon.classList.toggle('is-active', active);
          if (saveCount) saveCount.textContent = active ? '1' : '0';
          toast(active ? 'Post saved' : 'Post removed from saved');
        });
        comments.querySelector('form').addEventListener('submit', function (event) {
          event.preventDefault();
          var input = this.querySelector('input');
          if (!input.value.trim()) return;
          var commentCount = bar.querySelector('.js-comment-count-inline');
          if (commentCount) commentCount.textContent = '1';
          input.value = '';
          toast('Comment added');
        });
      }
    }
  });

  document.addEventListener('click', function (event) {
    if (event.target.closest('.community-post-menu, .community-post-fries')) return;
    document.querySelectorAll('.community-post-menu').forEach(function (menu) { menu.hidden = true; });
  });

  var feed = document.querySelector('.content');
  if (feed && feed.querySelector('.feed-filter') && !feed.querySelector('.community-feed-end')) {
    var endCard = document.createElement('div');
    endCard.className = 'community-feed-end';
    var endText = typeof window.__communityFeedEnd === 'string'
      ? window.__communityFeedEnd
      : 'You’ve reached the end of this community’s posts.';
    var endLabel = document.createElement('span');
    endLabel.textContent = endText;
    endCard.appendChild(endLabel);
    feed.appendChild(endCard);
  }

});
