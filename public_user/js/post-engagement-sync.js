/*! MSB post engagement sync — love/like/save/share/comment across cards, modal, reel, public. */
(function (global) {
  'use strict';

  if (global.MSBPostEngagement && global.MSBPostEngagement.__ready) return;

  var EVENT = 'msb:post-engagement';
  var CHANNEL = 'msb-post-engagement';
  var STORAGE_KEY = 'msb_post_engagement_v1';
  var tabId = 't' + Math.random().toString(36).slice(2) + Date.now().toString(36);
  var adapters = [];
  var applying = false;
  var channel = null;

  try {
    if (typeof BroadcastChannel !== 'undefined') {
      channel = new BroadcastChannel(CHANNEL);
      channel.onmessage = function (ev) {
        var msg = ev && ev.data;
        if (!msg || Number(msg.postId || 0) <= 0) return;
        if (msg.tabId && msg.tabId === tabId) return;
        applyLocal(Number(msg.postId), msg.patch || {}, { remote: true, source: msg.source || 'broadcast' });
      };
    }
  } catch (e) {}

  try {
    global.addEventListener('storage', function (ev) {
      if (!ev || ev.key !== STORAGE_KEY || !ev.newValue) return;
      try {
        var msg = JSON.parse(ev.newValue);
        if (!msg || Number(msg.postId || 0) <= 0) return;
        if (msg.tabId && msg.tabId === tabId) return;
        applyLocal(Number(msg.postId), msg.patch || {}, { remote: true, source: msg.source || 'storage' });
      } catch (err) {}
    });
  } catch (e) {}

  function num(v, fallback) {
    var n = Number(v);
    return isFinite(n) ? n : (fallback != null ? Number(fallback) : 0);
  }

  function has(obj, key) {
    return !!(obj && Object.prototype.hasOwnProperty.call(obj, key));
  }

  function hasValue(obj, key) {
    return has(obj, key) && obj[key] != null;
  }

  /** Combined badge next to the morphing love/thumbs icon — not love-only. */
  function reactionBadge(patch) {
    if (hasValue(patch, 'reaction_count')) return num(patch.reaction_count);
    if (hasValue(patch, 'love_count') && hasValue(patch, 'like_count')) {
      return num(patch.love_count) + num(patch.like_count);
    }
    return null;
  }

  function nextReaction(snap, nextKey) {
    snap = snap || {};
    var next = String(nextKey || '');
    if (next === 'none') next = '';
    var prev = String(snap.my_reaction || '');
    var total = hasValue(snap, 'reaction_count')
      ? num(snap.reaction_count)
      : num(snap.love_count);
    if (next && !prev) total += 1;
    else if (!next && prev) total = Math.max(0, total - 1);
    var love = num(snap.love_count);
    var like = num(snap.like_count);
    if (next === 'love' && prev !== 'love') love += 1;
    else if (prev === 'love' && next !== 'love') love = Math.max(0, love - 1);
    var prevNon = !!(prev && prev !== 'love');
    var nextNon = !!(next && next !== 'love');
    if (nextNon && !prevNon) like += 1;
    else if (prevNon && !nextNon) like = Math.max(0, like - 1);
    return {
      my_reaction: next,
      love_count: Math.max(0, love),
      like_count: Math.max(0, like),
      reaction_count: Math.max(0, total)
    };
  }

  function qsa(sel) {
    try { return Array.prototype.slice.call(document.querySelectorAll(sel)); }
    catch (e) { return []; }
  }

  function setText(nodes, value) {
    var text = String(value);
    (nodes || []).forEach(function (el) {
      if (el) el.textContent = text;
    });
  }

  function normalizePatch(raw) {
    raw = raw || {};
    var patch = {};
    var state = raw.state || {};

    if (hasValue(raw, 'love_count')) patch.love_count = num(raw.love_count);
    if (hasValue(raw, 'like_count')) patch.like_count = num(raw.like_count);
    if (hasValue(raw, 'reaction_count')) patch.reaction_count = num(raw.reaction_count);
    if (hasValue(raw, 'comment_count')) patch.comment_count = num(raw.comment_count);
    if (hasValue(raw, 'share_count')) patch.share_count = num(raw.share_count);
    if (hasValue(raw, 'save_count')) patch.save_count = num(raw.save_count);

    // Allow null/'' my_reaction so unlove clears active state across surfaces
    if (has(raw, 'my_reaction')) patch.my_reaction = String(raw.my_reaction || '');
    else if (raw.counts && has(raw.counts, 'my_reaction')) patch.my_reaction = String(raw.counts.my_reaction || '');

    if (raw.counts) {
      if (!has(patch, 'love_count') && hasValue(raw.counts, 'love_count')) patch.love_count = num(raw.counts.love_count);
      if (!has(patch, 'like_count') && hasValue(raw.counts, 'like_count')) patch.like_count = num(raw.counts.like_count);
      if (!has(patch, 'reaction_count') && hasValue(raw.counts, 'reaction_count')) patch.reaction_count = num(raw.counts.reaction_count);
      if (!has(patch, 'comment_count') && hasValue(raw.counts, 'comment_count')) patch.comment_count = num(raw.counts.comment_count);
      if (!has(patch, 'share_count') && hasValue(raw.counts, 'share_count')) patch.share_count = num(raw.counts.share_count);
      if (!has(patch, 'save_count') && hasValue(raw.counts, 'save_count')) patch.save_count = num(raw.counts.save_count);
    }
    if (!has(patch, 'reaction_count') && has(patch, 'love_count') && has(patch, 'like_count')) {
      patch.reaction_count = num(patch.love_count) + num(patch.like_count);
    }

    if (hasValue(raw, 'is_saved')) patch.is_saved = num(raw.is_saved) ? 1 : 0;
    else if (hasValue(raw, 'my_saved')) patch.is_saved = num(raw.my_saved) ? 1 : 0;
    else if (hasValue(state, 'saved')) patch.is_saved = num(state.saved) ? 1 : 0;

    if (hasValue(raw, 'is_shared')) patch.is_shared = num(raw.is_shared) ? 1 : 0;
    else if (hasValue(raw, 'my_shared')) patch.is_shared = num(raw.my_shared) ? 1 : 0;
    else if (hasValue(state, 'shared')) patch.is_shared = num(state.shared) ? 1 : 0;

    return patch;
  }

  function mfCards(postId) {
    var id = String(postId);
    return qsa('.mf-card[data-id="' + id + '"], .mf-card[data-post-id="' + id + '"], #profilePostsFeed .mf-card[data-id="' + id + '"]');
  }

  function publicCards(postId) {
    return qsa('.public-post-card[data-post-id="' + String(postId) + '"]');
  }

  function reelSlides(postId) {
    return qsa('.reel-slide[data-post-id="' + String(postId) + '"], .reel-stage[data-post-id="' + String(postId) + '"]');
  }

  function protectLiveEngagement(root, patch, opts) {
    if (!root || !patch) return patch;
    opts = opts || {};
    patch = Object.assign({}, patch);
    var engageAt = Number(root.getAttribute('data-engage-at') || 0);
    if (!engageAt || (Date.now() - engageAt) >= 8000) return patch;

    // Freeze engagement fields briefly after a local click so late hydrates/sync
    // cannot snap counts back to 0 (or restore stale toggles).
    delete patch.love_count;
    delete patch.like_count;
    delete patch.reaction_count;
    delete patch.save_count;
    delete patch.share_count;
    delete patch.my_reaction;
    delete patch.is_saved;
    delete patch.is_shared;
    return patch;
  }

  function applyMfCard(card, patch) {
    if (!card || !patch) return;
    patch = protectLiveEngagement(card, patch, {
      loveCountSel: '.mf-act.mf-love .mf-num',
      saveCountSel: '.mf-act.mf-save .mf-num',
      shareCountSel: '.mf-act.mf-share .mf-num',
      commentCountSel: '.mf-cmt, .mf-act.mf-comment .mf-num'
    });
    if (has(patch, 'comment_count')) {
      setText(card.querySelectorAll('.mf-cmt, .mf-num.mf-cmt, .mf-act.mf-comment .mf-num'), patch.comment_count);
    }
    var mfBadge = reactionBadge(patch);
    if (mfBadge != null) {
      setText(card.querySelectorAll('.mf-act.mf-love .mf-num'), mfBadge);
    }
    if (has(patch, 'like_count')) {
      setText(card.querySelectorAll('.mf-act.mf-like .mf-num'), patch.like_count);
    }
    if (has(patch, 'save_count')) {
      setText(card.querySelectorAll('.mf-act.mf-save .mf-num'), patch.save_count);
    }
    if (has(patch, 'share_count')) {
      setText(card.querySelectorAll('.mf-act.mf-share .mf-num'), patch.share_count);
    }

    if (has(patch, 'my_reaction')) {
      var my = String(patch.my_reaction || '');
      card.setAttribute('data-my-reaction', my);
      var loveBtn = card.querySelector('.mf-act.mf-love');
      var likeBtn = card.querySelector('.mf-act.mf-like');
      if (loveBtn) {
        loveBtn.classList.toggle('is-love', my === 'love');
        var heart = loveBtn.querySelector('.msb-pact-heart');
        if (heart) heart.classList.toggle('is-active', my === 'love');
        if (global.MSBReactions && typeof global.MSBReactions.applyReactionButton === 'function') {
          try { global.MSBReactions.applyReactionButton(loveBtn, my, 'love'); } catch (e) {}
        }
      }
      if (likeBtn) {
        likeBtn.classList.toggle('is-like', my === 'like');
        if (global.MSBReactions && typeof global.MSBReactions.applyLikeButton === 'function') {
          try { global.MSBReactions.applyLikeButton(likeBtn, my === 'like' ? my : ''); } catch (e) {}
        }
      }
    }

    if (has(patch, 'is_saved')) {
      var saved = Number(patch.is_saved) === 1;
      card.setAttribute('data-my-saved', saved ? '1' : '0');
      var saveBtn = card.querySelector('.mf-act.mf-save');
      if (saveBtn) {
        saveBtn.classList.toggle('is-save', saved);
        var bookmark = saveBtn.querySelector('.msb-pact-bookmark');
        if (bookmark) bookmark.classList.toggle('is-active', saved);
      }
    }
    if (has(patch, 'is_shared')) {
      var shared = Number(patch.is_shared) === 1;
      var shareBtn = card.querySelector('.mf-act.mf-share');
      if (shareBtn) shareBtn.classList.toggle('is-share', shared);
    }
  }

  function applyPublicCard(card, patch) {
    if (!card || !patch) return;
    patch = protectLiveEngagement(card, patch, {
      loveCountSel: '.js-love-count',
      saveCountSel: '.js-save-count',
      shareCountSel: '.js-share-count',
      commentCountSel: '.js-comment-count, .js-comment-count-inline'
    });
    if (has(patch, 'comment_count')) {
      card.setAttribute('data-comment-count', String(patch.comment_count));
      setText(card.querySelectorAll('.js-comment-count, .js-comment-count-inline'), patch.comment_count);
    }
    if (has(patch, 'love_count')) {
      card.setAttribute('data-love-count', String(patch.love_count));
    }
    if (has(patch, 'like_count')) {
      card.setAttribute('data-like-count', String(patch.like_count));
      setText(card.querySelectorAll('.js-like-count'), patch.like_count);
    }
    var pubBadge = reactionBadge(patch);
    if (pubBadge != null) {
      card.setAttribute('data-reaction-count', String(pubBadge));
      setText(card.querySelectorAll('.js-love-count, .js-reaction-count'), pubBadge);
    }
    if (has(patch, 'love_count') || has(patch, 'like_count')) {
      var loveN = has(patch, 'love_count') ? num(patch.love_count) : num(card.getAttribute('data-love-count'));
      var likeN = has(patch, 'like_count') ? num(patch.like_count) : num(card.getAttribute('data-like-count'));
      setText(card.querySelectorAll('.js-like-total'), loveN + likeN);
    }
    if (has(patch, 'share_count')) setText(card.querySelectorAll('.js-share-count'), patch.share_count);
    if (has(patch, 'save_count')) setText(card.querySelectorAll('.js-save-count'), patch.save_count);

    if (has(patch, 'my_reaction')) {
      var my = String(patch.my_reaction || '');
      card.setAttribute('data-my-reaction', my);
      Array.prototype.forEach.call(card.querySelectorAll('.js-react-love'), function (btn) {
        btn.classList.toggle('is-love', my === 'love');
        btn.classList.toggle('is-reacted', my !== '' && my !== 'love');
        if (global.MSBReactions && typeof global.MSBReactions.applyReactionButton === 'function') {
          try { global.MSBReactions.applyReactionButton(btn, my, 'love'); } catch (e) {}
        }
      });
      Array.prototype.forEach.call(card.querySelectorAll('.js-react-like'), function (btn) {
        btn.classList.toggle('is-like', my === 'like');
      });
    }
    if (has(patch, 'is_saved')) {
      var saved = Number(patch.is_saved) === 1;
      Array.prototype.forEach.call(card.querySelectorAll('.js-save-post'), function (btn) {
        btn.classList.toggle('is-save', saved);
      });
    }
    if (has(patch, 'is_shared')) {
      var shared = Number(patch.is_shared) === 1;
      Array.prototype.forEach.call(card.querySelectorAll('.js-share-post'), function (btn) {
        btn.classList.toggle('is-share', shared);
      });
    }
  }

  function applyReelSlide(slide, patch) {
    if (!slide || !patch) return;
    patch = protectLiveEngagement(slide, patch, {
      loveCountSel: '[data-count="love"]',
      saveCountSel: '[data-count="save"]',
      shareCountSel: '[data-count="share"]',
      commentCountSel: '[data-count="comment"]'
    });
    var reelBadge = reactionBadge(patch);
    if (reelBadge != null) {
      setText(slide.querySelectorAll('[data-count="love"]'), reelBadge);
    }
    if (has(patch, 'comment_count')) setText(slide.querySelectorAll('[data-count="comment"]'), patch.comment_count);
    if (has(patch, 'share_count')) setText(slide.querySelectorAll('[data-count="share"]'), patch.share_count);
    if (has(patch, 'save_count')) setText(slide.querySelectorAll('[data-count="save"]'), patch.save_count);

    if (has(patch, 'my_reaction')) {
      var my = String(patch.my_reaction || '');
      slide.setAttribute('data-my-reaction', my);
      var loveBtn = slide.querySelector('[data-act="love"]');
      if (loveBtn) {
        if (global.MSBReactions && typeof global.MSBReactions.applyReactionButton === 'function') {
          try { global.MSBReactions.applyReactionButton(loveBtn, my, 'love'); } catch (e) {}
        } else {
          var loveOn = my === 'love';
          loveBtn.classList.toggle('is-love', loveOn);
          var heart = loveBtn.querySelector('.msb-pact-heart');
          if (heart) heart.classList.toggle('is-active', loveOn);
        }
      }
    }
    if (has(patch, 'is_saved')) {
      var saved = Number(patch.is_saved) === 1;
      var saveBtn = slide.querySelector('[data-act="save"]');
      if (saveBtn) {
        saveBtn.classList.toggle('is-save', saved);
        var bookmark = saveBtn.querySelector('.msb-pact-bookmark');
        if (bookmark) bookmark.classList.toggle('is-active', saved);
      }
    }
    if (has(patch, 'is_shared')) {
      var shared = Number(patch.is_shared) === 1;
      var shareBtn = slide.querySelector('[data-act="share"]');
      if (shareBtn) shareBtn.classList.toggle('is-share', shared);
    }
  }

  function applyOverlay(postId, patch) {
    var activeId = Number(global.pvPostId || 0);
    if (!activeId || activeId !== Number(postId)) return;
    var ov = document.getElementById('pvOverlay');
    if (!ov) return;

    patch = protectLiveEngagement(ov, patch, {});

    var ovBadge = reactionBadge(patch);
    if (ovBadge != null) {
      var loveN = ov.querySelector('#pvLoveN');
      if (loveN) loveN.textContent = String(ovBadge);
    }
    if (has(patch, 'like_count')) {
      var likeN = ov.querySelector('#pvLikeN');
      if (likeN) likeN.textContent = String(patch.like_count);
    }
    if (has(patch, 'comment_count')) {
      var comN = ov.querySelector('#pvComN');
      if (comN) comN.textContent = String(patch.comment_count);
    }
    if (has(patch, 'share_count')) {
      var shareN = ov.querySelector('#pvShareN');
      if (shareN) shareN.textContent = String(patch.share_count);
    }
    if (has(patch, 'save_count')) {
      var saveN = ov.querySelector('#pvSaveN');
      if (saveN) saveN.textContent = String(patch.save_count);
    }

    var loveBtn = ov.querySelector('#pvLove');
    var likeBtn = ov.querySelector('#pvLike');
    var shareBtn = ov.querySelector('#pvShare');
    var saveBtn = ov.querySelector('#pvSave');

    if (has(patch, 'my_reaction')) {
      var my = String(patch.my_reaction || '');
      try { global.pvCurrentReaction = my; } catch (e) {}
      if (loveBtn) {
        loveBtn.classList.toggle('is-love', my === 'love');
        if (typeof global.pvApplyLoveReaction === 'function') {
          try { global.pvApplyLoveReaction(my); } catch (e1) {}
        } else if (global.MSBReactions && typeof global.MSBReactions.applyReactionButton === 'function') {
          try { global.MSBReactions.applyReactionButton(loveBtn, my, 'love'); } catch (e2) {}
        }
      }
      if (likeBtn) {
        likeBtn.classList.toggle('is-like', my === 'like');
        if (global.MSBReactions && typeof global.MSBReactions.applyLikeButton === 'function') {
          try { global.MSBReactions.applyLikeButton(likeBtn, my === 'like' ? my : ''); } catch (e3) {}
        }
      }
    }
    if (has(patch, 'is_shared') && shareBtn) shareBtn.classList.toggle('is-share', Number(patch.is_shared) === 1);
    if (has(patch, 'is_saved') && saveBtn) saveBtn.classList.toggle('is-save', Number(patch.is_saved) === 1);
  }

  function applyInstaViewer(postId, patch) {
    var cur = document.getElementById('curPostId');
    var curId = cur ? Number(cur.value || 0) : 0;
    if (!curId || curId !== Number(postId)) return;

    if (has(patch, 'love_count')) {
      setText(qsa('#loveCount, #loveCountV, #loveCountF, #loveCountLikes'), patch.love_count);
    }
    if (has(patch, 'like_count')) {
      setText(qsa('#likeCount, #likeCountV, #likeCountF'), patch.like_count);
    }
    if (has(patch, 'comment_count')) {
      setText(qsa('#commentCount, #commentCountV, #commentCountF, #commentCountTextF'), patch.comment_count);
    }
    if (has(patch, 'share_count')) {
      setText(qsa('#shareCount, #shareCountV, #shareCountF'), patch.share_count);
    }
    if (has(patch, 'save_count')) {
      setText(qsa('#saveCount, #saveCountV, #saveCountF'), patch.save_count);
    }

    if (has(patch, 'my_reaction') && typeof global.setReactionButtons === 'function') {
      try { global.setReactionButtons(String(patch.my_reaction || '')); } catch (e) {}
    } else if (has(patch, 'my_reaction')) {
      try { global.__myReaction = String(patch.my_reaction || ''); } catch (e2) {}
      var my = String(patch.my_reaction || '');
      qsa('#btnLove, #btnLoveV, #btnFooterLove').forEach(function (btn) {
        if (global.MSBReactions && typeof global.MSBReactions.applyReactionButton === 'function') {
          try { global.MSBReactions.applyReactionButton(btn, my, 'love'); } catch (e3) {}
        } else {
          btn.classList.toggle('is-love', my === 'love');
        }
      });
      qsa('#btnLike, #btnLikeV').forEach(function (btn) {
        btn.classList.toggle('is-like', my === 'like');
      });
    }

    if (has(patch, 'is_saved') || has(patch, 'is_shared')) {
      var saved = has(patch, 'is_saved') ? Number(patch.is_saved) === 1 : null;
      var shared = has(patch, 'is_shared') ? Number(patch.is_shared) === 1 : null;
      if (saved != null) {
        qsa('#btnSave, #btnSaveV, #btnFooterSave').forEach(function (btn) {
          btn.classList.toggle('is-save', saved);
        });
      }
      if (shared != null) {
        qsa('#btnShare, #btnShareV, #btnFooterShare').forEach(function (btn) {
          btn.classList.toggle('is-share', shared);
        });
      }
    }
  }

  function applyMenu(postId, patch) {
    if (has(patch, 'is_saved') && global.MSBPostCardMenu && typeof global.MSBPostCardMenu.syncBookmarkMenuState === 'function') {
      try { global.MSBPostCardMenu.syncBookmarkMenuState(postId, Number(patch.is_saved) === 1); } catch (e) {}
    }
  }

  function applyLocal(postId, rawPatch, meta) {
    postId = Number(postId || 0);
    if (!postId) return;
    var patch = normalizePatch(rawPatch);
    if (!Object.keys(patch).length) return;

    applying = true;
    try {
      mfCards(postId).forEach(function (card) { applyMfCard(card, patch); });
      publicCards(postId).forEach(function (card) { applyPublicCard(card, patch); });
      reelSlides(postId).forEach(function (slide) { applyReelSlide(slide, patch); });
      applyOverlay(postId, patch);
      applyInstaViewer(postId, patch);
      applyMenu(postId, patch);

      adapters.forEach(function (fn) {
        try { fn(postId, patch, meta || {}); } catch (e) {}
      });
    } finally {
      applying = false;
    }
  }

  function publish(postId, rawPatch, opts) {
    postId = Number(postId || 0);
    if (!postId || applying) return;
    opts = opts || {};
    var patch = normalizePatch(rawPatch);
    if (!Object.keys(patch).length) return;

    applyLocal(postId, patch, { remote: false, source: opts.source || 'local' });

    var msg = {
      postId: postId,
      patch: patch,
      source: opts.source || 'local',
      tabId: tabId,
      ts: Date.now()
    };

    try {
      global.dispatchEvent(new CustomEvent(EVENT, { detail: msg }));
    } catch (e) {}

    if (channel) {
      try { channel.postMessage(msg); } catch (e2) {}
    }

    try {
      global.localStorage.setItem(STORAGE_KEY, JSON.stringify(msg));
    } catch (e3) {}
  }

  function publishFromReact(postId, res, opts) {
    if (!res) return;
    publish(postId, res.counts || res, opts);
  }

  function publishFromTrack(postId, res, opts) {
    if (!res) return;
    publish(postId, {
      share_count: res.share_count,
      save_count: res.save_count,
      state: res.state || {},
      is_shared: res.state ? res.state.shared : undefined,
      is_saved: res.state ? res.state.saved : undefined
    }, opts);
  }

  function publishCommentCount(postId, count, opts) {
    publish(postId, { comment_count: num(count) }, opts);
  }

  function registerAdapter(fn) {
    if (typeof fn === 'function') adapters.push(fn);
  }

  try {
    global.addEventListener(EVENT, function (ev) {
      var detail = ev && ev.detail;
      if (!detail || Number(detail.postId || 0) <= 0) return;
      if (detail.tabId && detail.tabId === tabId) return;
      applyLocal(Number(detail.postId), detail.patch || {}, { remote: true, source: detail.source || 'event' });
    });
  } catch (e) {}

  global.MSBPostEngagement = {
    EVENT: EVENT,
    publish: publish,
    apply: applyLocal,
    normalize: normalizePatch,
    nextReaction: nextReaction,
    reactionBadge: reactionBadge,
    publishFromReact: publishFromReact,
    publishFromTrack: publishFromTrack,
    publishCommentCount: publishCommentCount,
    registerAdapter: registerAdapter,
    tabId: tabId,
    __ready: true
  };
})(window);
