/**
 * Profile gallery/tags/saved tile → post viewer.
 * Runs on window capture so it still works if other profile scripts error.
 */
(function () {
  'use strict';

  function escAttr(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;');
  }

  function idsFor(item) {
    var scope = 'gallery';
    var grid = item && item.closest ? item.closest('.ig-grid[data-grid-scope]') : null;
    if (grid) scope = String(grid.getAttribute('data-grid-scope') || 'gallery');
    var ids = [];
    if (scope === 'gallery' && typeof window.msbGalleryVisibleIds === 'function') {
      ids = window.msbGalleryVisibleIds() || [];
    }
    if (!ids.length && scope === 'gallery' && Array.isArray(window.GALLERY_GRID_IDS)) ids = window.GALLERY_GRID_IDS;
    if (!ids.length && scope === 'tags' && Array.isArray(window.TAGS_GRID_IDS)) ids = window.TAGS_GRID_IDS;
    if (!ids.length && (scope === 'saved' || scope === 'saved-view') && Array.isArray(window.SAVED_GRID_IDS)) ids = window.SAVED_GRID_IDS;
    return { ids: ids, scope: scope };
  }

  function overlayIsOpen() {
    var ov = document.getElementById('pvOverlay');
    return !!(ov && ov.classList.contains('show'));
  }

  function bindFallbackClose(ov) {
    if (!ov || ov.getAttribute('data-msb-grid-close') === '1') return;
    ov.setAttribute('data-msb-grid-close', '1');
    var closeBtn = document.getElementById('pvClose');
    function hide() {
      ov.classList.remove('show');
      ov.setAttribute('hidden', '');
      ov.style.display = 'none';
      ov.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('pv-body-lock');
    }
    if (closeBtn) closeBtn.addEventListener('click', hide);
    ov.addEventListener('mousedown', function (e) {
      if (e.target === ov) hide();
    });
  }

  function fallbackShow(postId) {
    var ov = document.getElementById('pvOverlay');
    if (!ov) return false;
    ov.removeAttribute('hidden');
    ov.style.display = 'flex';
    ov.classList.add('show');
    ov.setAttribute('aria-hidden', 'false');
    document.body.classList.add('pv-body-lock');
    bindFallbackClose(ov);
    var media = document.getElementById('pvMedia');
    if (media) media.innerHTML = '<div class="pv-loading-only">Loading…</div>';
    fetch('feed_api.php?ajax=view&id=' + encodeURIComponent(String(postId)) + '&count_view=1', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!media) return;
        if (!data || data.ok === false) {
          media.innerHTML = '<div class="pv-loading-only">Could not open this post.</div>';
          return;
        }
        var post = data.post || {};
        var atts = Array.isArray(data.attachments) ? data.attachments : [];
        var first = atts[0] || {};
        var src = String(first.file_path || first.thumb_path || post.file_path || post.thumb_path || post.preview_path || '');
        var type = String(first.type || first.atype || post.atype || post.preview_type || '').toLowerCase();
        var isVid = type.indexOf('video') >= 0 || /\.(mp4|mov|webm|m4v)(\?|$)/i.test(src);
        if (src && isVid) {
          media.innerHTML = '<video src="' + escAttr(src) + '" controls autoplay playsinline style="max-width:100%;max-height:100%;"></video>';
        } else if (src) {
          media.innerHTML = '<img src="' + escAttr(src) + '" alt="" style="max-width:100%;max-height:100%;object-fit:contain;">';
        } else {
          media.textContent = String(post.title || post.description || post.body || 'Post');
        }
        var name = document.getElementById('pvName');
        if (name) name.textContent = post.display_name || post.username || '';
      })
      .catch(function () {
        if (media) media.innerHTML = '<div class="pv-loading-only">Could not open this post.</div>';
      });
    return true;
  }

  function openPost(postId, item) {
    postId = parseInt(postId, 10) || 0;
    if (!postId) return false;
    var meta = idsFor(item);
    if (typeof window.pvOpenInGrid === 'function') {
      try {
        window.pvOpenInGrid(postId, meta.ids, meta.scope);
        if (overlayIsOpen()) return true;
      } catch (e1) {}
    }
    if (typeof window.pvOpenById === 'function') {
      try {
        window.pvOpenById(postId);
        if (overlayIsOpen()) return true;
      } catch (e2) {}
    }
    return fallbackShow(postId);
  }

  window.msbOpenProfileGridPost = function (postId, el) {
    return openPost(postId, el);
  };

  var savedDlg = {
    el: null,
    postId: 0,
    btn: null,
    busy: false
  };

  function savedDialog() {
    savedDlg.el = document.getElementById('savedRemoveDialog');
    return savedDlg.el;
  }

  function closeSavedDialog() {
    var dialog = savedDialog();
    if (dialog) {
      dialog.classList.remove('is-open');
      dialog.setAttribute('hidden', '');
    }
    savedDlg.postId = 0;
    if (savedDlg.btn) savedDlg.btn.disabled = false;
    savedDlg.btn = null;
    savedDlg.busy = false;
    var confirmBtn = document.getElementById('savedRemoveDialogConfirm');
    if (confirmBtn) confirmBtn.disabled = false;
  }

  function openSavedDialog(postId, triggerBtn) {
    postId = parseInt(postId, 10) || 0;
    var dialog = savedDialog();
    if (!dialog || !postId) return false;
    savedDlg.postId = postId;
    savedDlg.btn = triggerBtn || null;
    savedDlg.busy = false;
    var confirmBtn = document.getElementById('savedRemoveDialogConfirm');
    if (confirmBtn) confirmBtn.disabled = false;
    if (dialog.parentNode !== document.body) document.body.appendChild(dialog);
    dialog.removeAttribute('hidden');
    dialog.classList.add('is-open');
    try { if (confirmBtn) confirmBtn.focus(); } catch (eFocus) {}
    return true;
  }

  function removeSavedTile(postId) {
    if (typeof window.MSBProfileRemoveFromSaved === 'function') {
      try { window.MSBProfileRemoveFromSaved(postId); return; } catch (eRm) {}
    }
    document.querySelectorAll('.ig-saved-remove[data-unsave-post="' + String(postId) + '"]').forEach(function (btn) {
      var wrap = btn.closest('.ig-item-wrap') || btn.closest('.ig-item');
      if (wrap && wrap.parentNode) wrap.parentNode.removeChild(wrap);
    });
  }

  function confirmSavedRemove() {
    var postId = savedDlg.postId;
    var triggerBtn = savedDlg.btn;
    var confirmBtn = document.getElementById('savedRemoveDialogConfirm');
    if (!postId || savedDlg.busy) return;
    savedDlg.busy = true;
    if (confirmBtn) confirmBtn.disabled = true;
    if (triggerBtn) triggerBtn.disabled = true;
    var body = new URLSearchParams({ ajax: 'save', post_id: String(postId), save_action: 'remove' });
    fetch('feed_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      credentials: 'same-origin',
      body: body
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (!res || res.ok === false) {
        savedDlg.busy = false;
        if (confirmBtn) confirmBtn.disabled = false;
        if (triggerBtn) triggerBtn.disabled = false;
        return;
      }
      var stillSaved = Number(res.state && res.state.saved != null ? res.state.saved : 0) === 1;
      if (stillSaved) {
        savedDlg.busy = false;
        if (confirmBtn) confirmBtn.disabled = false;
        if (triggerBtn) triggerBtn.disabled = false;
        return;
      }
      savedDlg.btn = null;
      closeSavedDialog();
      removeSavedTile(postId);
    }).catch(function () {
      savedDlg.busy = false;
      if (confirmBtn) confirmBtn.disabled = false;
      if (triggerBtn) triggerBtn.disabled = false;
    });
  }

  function bindSavedDialogChrome() {
    if (window.__msbSavedRemoveUiBound) return;
    window.__msbSavedRemoveUiBound = true;
    var dialog = savedDialog();
    var cancelBtn = document.getElementById('savedRemoveDialogCancel');
    var closeBtn = document.getElementById('savedRemoveDialogClose');
    var confirmBtn = document.getElementById('savedRemoveDialogConfirm');
    if (dialog) {
      dialog.addEventListener('click', function (e) {
        if (e.target === dialog) closeSavedDialog();
      });
    }
    if (cancelBtn) cancelBtn.addEventListener('click', function (e) {
      e.preventDefault();
      closeSavedDialog();
    });
    if (closeBtn) closeBtn.addEventListener('click', function (e) {
      e.preventDefault();
      closeSavedDialog();
    });
    if (confirmBtn) confirmBtn.addEventListener('click', function (e) {
      e.preventDefault();
      confirmSavedRemove();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && savedDialog() && savedDialog().classList.contains('is-open')) {
        e.preventDefault();
        closeSavedDialog();
      }
    });
  }

  function fromEvent(e) {
    if (!e || e.button) return;
    var t = e.target;
    if (!t) return;
    if (t.nodeType === 3) t = t.parentElement;
    if (!t || !t.closest) return;
    if (t.closest('#pvOverlay, #savedRemoveDialog')) return;

    var unsave = t.closest('.ig-saved-remove');
    if (unsave) {
      if (e.cancelable && e.preventDefault) e.preventDefault();
      if (e.stopPropagation) e.stopPropagation();
      if (e.stopImmediatePropagation) e.stopImmediatePropagation();
      var unsaveId = unsave.getAttribute('data-unsave-post') || '0';
      bindSavedDialogChrome();
      openSavedDialog(unsaveId, unsave);
      return;
    }

    var item = t.closest('#panel-gallery .ig-item, .ig-grid .ig-item');
    if (!item) return;
    if (e.cancelable && e.preventDefault) e.preventDefault();
    openPost(item.getAttribute('data-post-id'), item);
  }

  bindSavedDialogChrome();
  window.addEventListener('click', fromEvent, true);
})();
