/**
 * Discover-parity engagement for community profile cards.
 * Calls feed_api.php (same endpoints as public.php Discover).
 * Does not load post-card menu modals — share uses API + clipboard fallback.
 * Comments: leftbar.php owns .js-open-comments when available.
 */
(function () {
  'use strict';

  var API = 'feed_api.php';

  function postForm(url, data) {
    var body = new URLSearchParams();
    Object.keys(data || {}).forEach(function (key) {
      body.set(key, String(data[key]));
    });
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body
    }).then(function (r) { return r.json(); });
  }

  function getJson(url) {
    return fetch(url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); });
  }

  function cardFor(el) {
    return el && el.closest ? el.closest('.public-post-card, article.post[data-post-id]') : null;
  }

  function postIdFrom(el, card) {
    return Number(
      (el && el.getAttribute && el.getAttribute('data-post-id')) ||
      (card && card.getAttribute('data-post-id')) ||
      0
    );
  }

  function readSnap(card) {
    if (!card) {
      return { my_reaction: '', love_count: 0, like_count: 0, reaction_count: 0, is_shared: 0, is_saved: 0, share_count: 0, save_count: 0, comment_count: 0 };
    }
    return {
      my_reaction: String(card.getAttribute('data-my-reaction') || ''),
      love_count: Number(card.getAttribute('data-love-count') || 0),
      like_count: Number(card.getAttribute('data-like-count') || 0),
      reaction_count: Number(card.getAttribute('data-reaction-count') || 0),
      is_shared: Number(card.getAttribute('data-is-shared') || 0),
      is_saved: Number(card.getAttribute('data-is-saved') || 0),
      share_count: Number(card.getAttribute('data-share-count') || 0),
      save_count: Number(card.getAttribute('data-save-count') || 0),
      comment_count: Number(card.getAttribute('data-comment-count') || 0)
    };
  }

  function trackFromRes(res, fallback) {
    fallback = fallback || {};
    var state = (res && res.state) || {};
    return {
      is_shared: Number(state.shared != null ? state.shared : (fallback.is_shared || 0)),
      is_saved: Number(state.saved != null ? state.saved : (fallback.is_saved || 0)),
      share_count: Number(res && res.share_count != null ? res.share_count : (fallback.share_count || 0)),
      save_count: Number(res && res.save_count != null ? res.save_count : (fallback.save_count || 0))
    };
  }

  function setText(root, sel, value) {
    if (!root) return;
    root.querySelectorAll(sel).forEach(function (node) {
      node.textContent = String(value);
    });
  }

  function toast(msg) {
    try {
      var note = document.createElement('div');
      note.className = 'community-post-toast';
      note.textContent = msg;
      document.body.appendChild(note);
      window.setTimeout(function () { note.remove(); }, 1800);
    } catch (_e) {}
  }

  function applyReaction(card, postId, counts) {
    if (!card) return;
    var my = String(counts.my_reaction || '');
    var love = Math.max(0, Number(counts.love_count || 0));
    var like = Math.max(0, Number(counts.like_count || 0));
    var reaction = Math.max(0, Number(counts.reaction_count != null ? counts.reaction_count : (love + like)));
    card.setAttribute('data-my-reaction', my);
    card.setAttribute('data-love-count', String(love));
    card.setAttribute('data-like-count', String(like));
    card.setAttribute('data-reaction-count', String(reaction));

    card.querySelectorAll('.js-react-love').forEach(function (btn) {
      if (Number(btn.getAttribute('data-post-id') || 0) !== postId) return;
      btn.classList.toggle('is-love', my === 'love');
      btn.classList.toggle('is-loved', my === 'love');
      var icon = btn.querySelector('.msb-pact-heart');
      if (icon) icon.classList.toggle('is-active', my === 'love');
      if (window.MSBReactions && typeof window.MSBReactions.applyReactionButton === 'function') {
        try { window.MSBReactions.applyReactionButton(btn, my, 'love'); } catch (_e) {}
      }
    });
    setText(card, '.js-love-count', love);
  }

  function applyTrack(card, postId, counts) {
    if (!card) return;
    var shared = !!Number(counts.is_shared || 0);
    var saved = !!Number(counts.is_saved || 0);
    var shareCount = Math.max(0, Number(counts.share_count || 0));
    var saveCount = Math.max(0, Number(counts.save_count || 0));
    card.setAttribute('data-is-shared', shared ? '1' : '0');
    card.setAttribute('data-is-saved', saved ? '1' : '0');
    card.setAttribute('data-share-count', String(shareCount));
    card.setAttribute('data-save-count', String(saveCount));

    card.querySelectorAll('.js-share-post').forEach(function (btn) {
      if (Number(btn.getAttribute('data-post-id') || 0) !== postId) return;
      btn.classList.toggle('is-share', shared);
    });
    card.querySelectorAll('.js-save-post').forEach(function (btn) {
      if (Number(btn.getAttribute('data-post-id') || 0) !== postId) return;
      btn.classList.toggle('is-save', saved);
      btn.classList.toggle('is-active', saved);
      var icon = btn.querySelector('.msb-pact-bookmark');
      if (icon) icon.classList.toggle('is-active', saved);
    });
    setText(card, '.js-share-count', shareCount);
    setText(card, '.js-save-count', saveCount);
  }

  function applyCommentCount(card, count) {
    if (!card) return;
    count = Math.max(0, Number(count || 0));
    card.setAttribute('data-comment-count', String(count));
    setText(card, '.js-comment-count-inline, .js-comment-count', count);
  }

  function copyShareLink(postId) {
    var url;
    try {
      url = (window.location.origin || '') + (window.location.pathname || '').replace(/[^/]+$/, '') + 'post_view.php?id=' + encodeURIComponent(String(postId));
    } catch (_e) {
      url = (window.location.origin || '') + '/post_view.php?id=' + encodeURIComponent(String(postId));
    }
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).catch(function () {});
      }
    } catch (_e2) {}
  }

  function hydrateCard(card) {
    if (!card || !card.getAttribute) return;
    var postId = Number(card.getAttribute('data-post-id') || 0);
    if (postId <= 0) return;

    getJson(API + '?ajax=track_counts&post_id=' + encodeURIComponent(String(postId)))
      .then(function (res) {
        if (!res || !res.ok) return;
        applyTrack(card, postId, trackFromRes(res));
      })
      .catch(function () {});

    getJson(API + '?ajax=view&id=' + encodeURIComponent(String(postId)))
      .then(function (res) {
        if (!res || !res.ok) return;
        if (res.counts) {
          applyReaction(card, postId, res.counts);
          applyTrack(card, postId, trackFromRes({
            share_count: res.counts.share_count,
            save_count: res.counts.save_count,
            state: {
              shared: res.counts.is_shared != null ? res.counts.is_shared : res.counts.my_shared,
              saved: res.counts.is_saved != null ? res.counts.is_saved : res.counts.my_saved
            }
          }));
          if (res.counts.comment_count != null) applyCommentCount(card, res.counts.comment_count);
        } else if (res.post) {
          applyReaction(card, postId, {
            my_reaction: res.post.my_reaction || '',
            love_count: res.post.love_count || 0,
            like_count: res.post.like_count || 0,
            reaction_count: res.post.reaction_count || 0
          });
        }
        if (res.comment_count != null) applyCommentCount(card, res.comment_count);
        else if (res.comments && Array.isArray(res.comments)) applyCommentCount(card, res.comments.length);
      })
      .catch(function () {});
  }

  document.addEventListener('click', function (e) {
    var loveBtn = e.target.closest && e.target.closest('.js-react-love');
    if (loveBtn && loveBtn.closest('article.post, .public-post-card') && !loveBtn.closest('#reelApp, .reel-slide')) {
      if (e.target.closest('.js-love-count, .js-open-reactors')) return;
      var card = cardFor(loveBtn);
      var postId = postIdFrom(loveBtn, card);
      if (!postId || loveBtn.disabled) return;
      e.preventDefault();
      e.stopPropagation();
      var snap = readSnap(card);
      var nextReaction = snap.my_reaction === 'love' ? 'none' : 'love';
      var optimistic = {
        my_reaction: nextReaction === 'none' ? '' : 'love',
        love_count: Math.max(0, snap.love_count + (nextReaction === 'none' ? -1 : 1)),
        like_count: snap.like_count,
        reaction_count: Math.max(0, snap.reaction_count + (nextReaction === 'none' ? -1 : (snap.my_reaction ? 0 : 1)))
      };
      applyReaction(card, postId, optimistic);
      loveBtn.disabled = true;
      postForm(API + '?ajax=react', { post_id: postId, reaction: nextReaction })
        .then(function (res) {
          if (res && res.ok) applyReaction(card, postId, Object.assign({}, optimistic, res.counts || {}));
          else applyReaction(card, postId, snap);
        })
        .catch(function () { applyReaction(card, postId, snap); })
        .finally(function () { loveBtn.disabled = false; });
      return;
    }

    // Comments: leftbar capture handler owns .js-open-comments when leftbar is present.
    // Fallback inline composer if no leftbar / TTComments.
    var commentBtn = e.target.closest && e.target.closest('.js-open-comments');
    if (commentBtn && commentBtn.closest('article.post, .public-post-card')) {
      var cardC = cardFor(commentBtn);
      var postIdC = postIdFrom(commentBtn, cardC);
      if (!postIdC) return;
      if (window.TTComments && typeof window.TTComments.setPost === 'function') {
        // leftbar already handles in capture; do not double-handle
        return;
      }
      e.preventDefault();
      e.stopPropagation();
      var inline = cardC && cardC.querySelector('.community-post-comments');
      if (inline) {
        inline.classList.toggle('is-open');
        if (inline.classList.contains('is-open')) {
          var input = inline.querySelector('input');
          if (input) input.focus();
        }
      }
      return;
    }

    var shareBtn = e.target.closest && e.target.closest('.js-share-post');
    if (shareBtn && shareBtn.closest('article.post, .public-post-card') && !shareBtn.closest('#reelApp, .reel-slide')) {
      if (e.target.closest('.js-share-count, .js-open-reactors')) return;
      var cardS = cardFor(shareBtn);
      var postIdS = postIdFrom(shareBtn, cardS);
      if (!postIdS || shareBtn.disabled) return;
      e.preventDefault();
      e.stopPropagation();
      var snapS = readSnap(cardS);
      var nextShared = 1;
      applyTrack(cardS, postIdS, {
        is_shared: nextShared,
        is_saved: snapS.is_saved,
        share_count: Math.max(snapS.share_count, snapS.share_count + (snapS.is_shared ? 0 : 1)),
        save_count: snapS.save_count
      });
      shareBtn.disabled = true;
      copyShareLink(postIdS);
      postForm(API + '?ajax=share', { post_id: postIdS, share_action: 'add' })
        .then(function (res) {
          if (res && res.ok) {
            applyTrack(cardS, postIdS, trackFromRes(res, snapS));
            toast('Link copied');
          } else {
            applyTrack(cardS, postIdS, snapS);
            toast((res && res.error) ? String(res.error) : 'Unable to share.');
          }
        })
        .catch(function () {
          applyTrack(cardS, postIdS, snapS);
          toast('Unable to share.');
        })
        .finally(function () { shareBtn.disabled = false; });
      return;
    }

    var saveBtn = e.target.closest && e.target.closest('.js-save-post');
    if (saveBtn && saveBtn.closest('article.post, .public-post-card') && !saveBtn.closest('#reelApp, .reel-slide')) {
      if (e.target.closest('.js-save-count, .js-open-reactors')) return;
      var cardV = cardFor(saveBtn);
      var postIdV = postIdFrom(saveBtn, cardV);
      if (!postIdV || saveBtn.disabled) return;
      e.preventDefault();
      e.stopPropagation();
      var snapV = readSnap(cardV);
      var nextSaved = snapV.is_saved ? 0 : 1;
      applyTrack(cardV, postIdV, {
        is_shared: snapV.is_shared,
        is_saved: nextSaved,
        share_count: snapV.share_count,
        save_count: Math.max(0, snapV.save_count + (nextSaved ? 1 : -1))
      });
      saveBtn.disabled = true;
      postForm(API + '?ajax=save', { post_id: postIdV })
        .then(function (res) {
          if (res && res.ok) {
            var track = trackFromRes(res, snapV);
            applyTrack(cardV, postIdV, track);
            toast(Number(track.is_saved)
              ? 'Added to Favorites. Find it in Settings → Favorites.'
              : 'Removed from Favorites.');
          } else {
            applyTrack(cardV, postIdV, snapV);
            toast((res && res.error) ? String(res.error) : 'Unable to save.');
          }
        })
        .catch(function () {
          applyTrack(cardV, postIdV, snapV);
          toast('Unable to save.');
        })
        .finally(function () { saveBtn.disabled = false; });
    }
  }, true);

  function bootHydrate() {
    document.querySelectorAll('article.post[data-post-id], .public-post-card[data-post-id]').forEach(hydrateCard);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      window.setTimeout(bootHydrate, 80);
    });
  } else {
    window.setTimeout(bootHydrate, 80);
  }

  window.MSBCommunityEngagement = { hydrateCard: hydrateCard, hydrateAll: bootHydrate };

  function bindLovePicker() {
    if (!window.MSBReactions || typeof window.MSBReactions.bindLikePicker !== 'function') return;
    window.MSBReactions.bindLikePicker('.js-react-love', function (btn, reaction) {
      if (!btn || !btn.closest('article.post, .public-post-card')) return;
      var postId = Number(btn.getAttribute('data-post-id') || 0);
      if (!postId || btn.disabled || !reaction) return;
      var next = String(reaction || 'none');
      var card = cardFor(btn);
      var snap = readSnap(card);
      var prev = String(snap.my_reaction || '');
      if (next === 'none' && !prev) return;
      if (next !== 'none' && prev === next) return;
      var optimistic = Object.assign({}, snap, {
        my_reaction: next === 'none' ? '' : next,
        reaction_count: Math.max(0, Number(snap.reaction_count || 0) + (!prev && next !== 'none' ? 1 : 0) - (prev && next === 'none' ? 1 : 0))
      });
      applyReaction(card, postId, optimistic);
      btn.disabled = true;
      postForm(API + '?ajax=react', { post_id: postId, reaction: next })
        .then(function (res) {
          if (res && res.ok) {
            var counts = Object.assign({}, optimistic, res.counts || {});
            if (counts.my_reaction == null) counts.my_reaction = next === 'none' ? '' : next;
            applyReaction(card, postId, counts);
          } else {
            applyReaction(card, postId, snap);
          }
        })
        .catch(function () { applyReaction(card, postId, snap); })
        .finally(function () { btn.disabled = false; });
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { window.setTimeout(bindLovePicker, 150); });
  } else {
    window.setTimeout(bindLovePicker, 150);
  }
})();
