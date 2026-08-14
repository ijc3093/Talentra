<?php
/**
 * Shared post viewer modal JS — defines window.pvOpenById / window.pvClose.
 */
if (defined('MSB_POST_VIEWER_MODAL_JS')) {
    return;
}
define('MSB_POST_VIEWER_MODAL_JS', true);

$pvModalApiUrl = isset($pvModalApiUrl) ? (string)$pvModalApiUrl : 'feed_api.php';
$pvModalApiUrlJson = json_encode($pvModalApiUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<script>
(function(){
  'use strict';

  var PV_API = <?php echo $pvModalApiUrlJson; ?>;
  var pvPostId = 0;
  var pvCurrentPost = null;
  var pvCurrentAttachments = [];
  var pvReplyTo = 0;
  var pvReplyToName = '';
  var pvCommentsCache = [];
  var pvCollapsedReplyIds = new Set();
  var pvMaxReplyCurveDepth = 4;
  var pvCurrentReaction = '';
  var pvLoadSeq = 0;
  var pvScrollY = 0;

  function pvSetActivePostId(id){
    pvPostId = Number(id || 0) || 0;
    try { window.pvPostId = pvPostId; } catch (e) {}
  }
  function pvSetActiveReaction(reaction){
    pvCurrentReaction = String(reaction || '');
    try { window.pvCurrentReaction = pvCurrentReaction; } catch (e) {}
  }
  function pvPublishReact(postId, data){
    if (!window.MSBPostEngagement) return;
    try { window.MSBPostEngagement.publishFromReact(postId, data || {}, { source: 'post-viewer' }); } catch (e) {}
  }
  function pvPublishTrack(postId, res){
    if (!window.MSBPostEngagement) return;
    try { window.MSBPostEngagement.publishFromTrack(postId, res || {}, { source: 'post-viewer' }); } catch (e) {}
  }
  function pvPublishCommentCount(postId, count){
    if (!window.MSBPostEngagement) return;
    try { window.MSBPostEngagement.publishCommentCount(postId, count, { source: 'post-viewer' }); } catch (e) {}
  }

  var pv = {
    ov: document.getElementById('pvOverlay'),
    media: null,
    body: null,
    caption: null,
    comments: null,
    avatar: null,
    name: null,
    meta: null,
    love: null,
    like: null,
    share: null,
    save: null,
    focusComment: null,
    text: null,
    postBtn: null,
    loveN: null,
    likeN: null,
    comN: null,
    shareN: null,
    saveN: null,
    viewN: null,
    replyBar: null,
    replyLead: null,
    replyName: null,
    replyCancel: null,
    close: null,
    left: null
  };

  if (!pv.ov) return;

  pv.media = pv.ov.querySelector('#pvMedia');
  pv.body = pv.ov.querySelector('#pvBody');
  pv.caption = pv.ov.querySelector('#pvCaption');
  pv.comments = pv.ov.querySelector('#pvComments');
  pv.avatar = pv.ov.querySelector('#pvAvatar');
  pv.name = pv.ov.querySelector('#pvName');
  pv.meta = pv.ov.querySelector('#pvMeta');
  pv.love = pv.ov.querySelector('#pvLove');
  pv.like = pv.ov.querySelector('#pvLike');
  pv.share = pv.ov.querySelector('#pvShare');
  pv.save = pv.ov.querySelector('#pvSave');
  pv.focusComment = pv.ov.querySelector('#pvComment');
  pv.text = pv.ov.querySelector('#pvText');
  pv.postBtn = pv.ov.querySelector('#pvPostBtn');
  pv.loveN = pv.ov.querySelector('#pvLoveN');
  pv.likeN = pv.ov.querySelector('#pvLikeN');
  pv.comN = pv.ov.querySelector('#pvComN');
  pv.shareN = pv.ov.querySelector('#pvShareN');
  pv.saveN = pv.ov.querySelector('#pvSaveN');
  pv.viewN = pv.ov.querySelector('#pvViewN');
  pv.replyBar = pv.ov.querySelector('#pvReplyBar');
  pv.replyLead = pv.ov.querySelector('#pvReplyLead');
  pv.replyName = pv.ov.querySelector('#pvReplyName');
  pv.replyCancel = pv.ov.querySelector('#pvReplyCancel');
  pv.close = pv.ov.querySelector('#pvClose');
  pv.left = pv.ov.querySelector('.pv-left');

  window.pv = pv;

  function pvEsc(s){
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function pvSetVh(){
    try {
      var vh = (window.innerHeight || document.documentElement.clientHeight || 0) * 0.01;
      document.documentElement.style.setProperty('--vh', vh + 'px');
    } catch (e) {}
  }
  pvSetVh();
  window.addEventListener('resize', pvSetVh, {passive: true});
  window.addEventListener('orientationchange', function(){ setTimeout(pvSetVh, 120); }, {passive: true});

  function pvLockBodyScroll(){
    try {
      pvScrollY = window.scrollY || document.documentElement.scrollTop || 0;
      document.body.classList.add('pv-body-lock');
      document.body.style.position = 'fixed';
      document.body.style.top = (-pvScrollY) + 'px';
      document.body.style.left = '0';
      document.body.style.right = '0';
      document.body.style.width = '100%';
    } catch (e) {}
  }

  function pvUnlockBodyScroll(){
    try {
      document.body.classList.remove('pv-body-lock');
      var top = document.body.style.top;
      document.body.style.position = '';
      document.body.style.top = '';
      document.body.style.left = '';
      document.body.style.right = '';
      document.body.style.width = '';
      var y = top ? Math.abs(parseInt(top, 10)) : (pvScrollY || 0);
      window.scrollTo(0, y);
    } catch (e) {}
  }

  function pvAvatarUrlFor(it, size){
    try {
      if (typeof avatarUrlFor === 'function') return avatarUrlFor(it || {}, size || 96);
    } catch (e) {}
    it = it || {};
    size = Number(size || 96);
    var params = [];
    var uid = Number(it.user_id || it.id || 0);
    var email = String(it.email || '').trim();
    var fc = String(it.friend_code || '').trim();
    var un = String(it.username || '').trim();
    var nm = String(it.display_name || it.name || un || 'User').trim();
    if (uid > 0) params.push('u=' + encodeURIComponent(String(uid)));
    if (email) params.push('email=' + encodeURIComponent(email));
    if (fc) params.push('friend_code=' + encodeURIComponent(fc));
    if (un) params.push('username=' + encodeURIComponent(un));
    if (nm) params.push('name=' + encodeURIComponent(nm));
    params.push('s=' + encodeURIComponent(String(size)));
    return 'avatar.php?' + params.join('&');
  }

  function pvTimeAgo(ts){
    var raw = String(ts == null ? '' : ts).trim();
    if (!raw) return '';
    // MySQL "YYYY-MM-DD HH:MM:SS" — parse as local wall time (avoid UTC skew → "-Ns").
    var normalized = raw;
    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/.test(raw)) {
      normalized = raw.replace(' ', 'T');
    }
    var t = Date.parse(normalized);
    if (!t) {
      t = Date.parse(raw.replace(/-/g, '/'));
    }
    if (!t) return '';
    var sec = Math.floor((Date.now() - t) / 1000);
    if (!isFinite(sec)) return '';
    if (sec < 0) sec = 0;
    if (sec < 60) return sec + 's';
    var m = Math.floor(sec / 60); if (m < 60) return m + 'm';
    var h = Math.floor(m / 60); if (h < 24) return h + 'h';
    var d = Math.floor(h / 24); if (d < 7) return d + 'd';
    var w = Math.floor(d / 7); if (w < 4) return w + 'w';
    var mo = Math.floor(d / 30); if (mo < 12) return mo + 'mo';
    return Math.floor(d / 365) + 'y';
  }

  function pvFormatRichText(text){
    var src = String(text == null ? '' : text).replace(/\r\n?/g, '\n').trim();
    if (!src) return '';
    return '<div class="pv-richtext">' + src.split('\n').map(function(line){
      var html = pvEsc(line).replace(/  /g, ' &nbsp;');
      html = html.replace(/(^|[^A-Za-z0-9_])@([A-Za-z0-9_]{2,50})\b/g, function(_, pre, user){
        return pre + '<a class="msb-mention" href="profile.php?username=' + encodeURIComponent(user) + '">@' + user + '</a>';
      });
      return '<p class="pv-rich-p">' + html + '</p>';
    }).join('') + '</div>';
  }

  function pvTruncateText(s, maxSent){
    var txt = String(s == null ? '' : s).trim();
    var max = Math.max(1, Number(maxSent || 3));
    var maxChars = 170;
    if (!txt) return { short: '', full: '', truncated: false };
    var sents = txt.split(/[.!?]+/).map(function(x){ return String(x || '').trim(); }).filter(Boolean);
    if (sents.length <= max && txt.length <= maxChars) {
      return { short: txt, full: txt, truncated: false };
    }
    if (sents.length > max) {
      return { short: sents.slice(0, max).join('. ') + '.', full: txt, truncated: true };
    }
    var short = txt.slice(0, maxChars).trimEnd();
    var sp = short.lastIndexOf(' ');
    if (sp > Math.floor(maxChars * 0.6)) short = short.slice(0, sp);
    return { short: short, full: txt, truncated: true };
  }

  function pvSetReply(parentId, displayName, mode){
    pvReplyTo = parentId || 0;
    pvReplyToName = displayName || '';
    var isCommentMode = String(mode || 'Reply') === 'Comment';
    if (pvReplyTo > 0) {
      if (pv.replyLead) pv.replyLead.textContent = isCommentMode ? 'Commenting on' : 'Replying to';
      if (pv.replyName) pv.replyName.textContent = pvReplyToName || '—';
      if (pv.replyBar) pv.replyBar.style.display = '';
      if (pv.text) pv.text.placeholder = (isCommentMode ? 'Comment on ' : 'Reply to ') + (pvReplyToName || 'comment');
    } else {
      if (pv.replyBar) pv.replyBar.style.display = 'none';
      if (pv.replyLead) pv.replyLead.textContent = 'Replying to';
      if (pv.text) pv.text.placeholder = 'Add comment...';
    }
  }

  async function pvJson(url, opts){
    var res = await fetch(url, opts || {});
    var data = await res.json().catch(function(){ return null; });
    if (!data || data.ok === false) {
      throw new Error((data && data.error) ? data.error : 'Request failed');
    }
    return data;
  }

  function pvSyncMediaOrientation(){
    if (!pv.ov || !pv.media) return;
    var el = null;
    var carousel = pv.media.querySelector('.mf-media-carousel, .media-carousel');
    if (carousel) {
      var idx = Math.max(0, Number(carousel.getAttribute('data-index') || 0));
      var slides = carousel.querySelectorAll('.mf-media-slide, .media-slide');
      var slide = slides[idx] || slides[0];
      el = slide ? slide.querySelector('img, video') : null;
    }
    if (!el) el = pv.media.querySelector('img, video');
    var w = 0, h = 0;
    if (el) {
      if (String(el.tagName || '').toUpperCase() === 'VIDEO') {
        w = Number(el.videoWidth || 0);
        h = Number(el.videoHeight || 0);
      } else {
        w = Number(el.naturalWidth || 0);
        h = Number(el.naturalHeight || 0);
      }
      if ((!w || !h) && el.getBoundingClientRect) {
        var r = el.getBoundingClientRect();
        w = Number(r.width || 0);
        h = Number(r.height || 0);
      }
    }
    var isPortrait = (w > 0 && h > 0) ? (h > w * 1.05) : false;
    var isLandscape = (w > 0 && h > 0) ? (w >= h * 0.95) : !isPortrait;
    pv.ov.classList.toggle('pv-is-portrait', !!isPortrait);
    pv.ov.classList.toggle('pv-is-landscape', !!isLandscape && !isPortrait);
  }

  function pvBindMediaOrientation(){
    try {
      var nodes = pv.media ? pv.media.querySelectorAll('img, video') : [];
      nodes.forEach(function(el){
        if (!el || el.__pvOrientBound) return;
        el.__pvOrientBound = true;
        var sync = function(){ pvSyncMediaOrientation(); };
        el.addEventListener('load', sync);
        el.addEventListener('loadedmetadata', sync);
        if (String(el.tagName || '').toUpperCase() === 'IMG') {
          if (el.complete && el.naturalWidth) sync();
        } else if (el.readyState >= 1) sync();
      });
      pvSyncMediaOrientation();
    } catch (e) {}
  }

  function pvAttSrc(a){
    return String((a && (a.url || a.file_path || a.thumb_url || a.thumb_path)) || '').trim();
  }

  function pvAttKind(a, url){
    var type = String((a && a.type) || '').toLowerCase();
    if (type === 'video' || /\.(mp4|webm|ogg|mov|m4v)(\?.*)?$/i.test(url)) return 'video';
    if (type === 'pdf' || /\.(pdf|docx|pptx|doc)(\?.*)?$/i.test(url)) return 'pdf';
    return 'image';
  }

  function pvSlideInner(a){
    var url = pvAttSrc(a);
    var thumb = String((a && (a.thumb_url || a.thumb_path)) || '').trim();
    var kind = pvAttKind(a, url);
    if (kind === 'video') return '<video src="' + pvEsc(url) + '" controls playsinline preload="metadata"></video>';
    if (kind === 'pdf') return '<iframe src="' + pvEsc(url) + '" style="width:100%;height:100%;border:0;"></iframe>';
    return '<img src="' + pvEsc(thumb || url) + '" alt="" />';
  }

  function pvRenderMedia(post, atts){
    var title = String((post && post.title) || '').trim();
    var desc = String((post && post.description) || '').trim();
    var body = String((post && post.body) || '').trim();
    try {
      var hasMedia = Array.isArray(atts) && atts.length > 0;
      var textOnly = !hasMedia && ((desc || body).trim() !== '');
      var isSmall = window.matchMedia && window.matchMedia('(max-width: 900px)').matches;
      if (pv.left) pv.left.classList.toggle('pv-left-scroll', !!(isSmall && textOnly));
    } catch (e) {}

    if (Array.isArray(atts) && atts.length > 1) {
      var slides = '';
      atts.forEach(function(a, i){
        slides += '<div class="media-slide mf-media-slide" data-slide-index="' + i + '">' + pvSlideInner(a) + '</div>';
      });
      pv.media.innerHTML =
        '<div class="media-carousel mf-media-carousel" data-index="0">' +
          '<div class="media-slides mf-media-slides">' + slides + '</div>' +
          '<button type="button" class="media-nav mf-media-nav prev js-pv-media-prev" aria-label="Previous media"><i class="fa fa-chevron-left"></i></button>' +
          '<button type="button" class="media-nav mf-media-nav next js-pv-media-next" aria-label="Next media"><i class="fa fa-chevron-right"></i></button>' +
          pvMediaDots(atts.length) +
        '</div>';
      pvSetMediaCarouselIndex(pv.media.querySelector('.mf-media-carousel'), 0);
      pvBindMediaOrientation();
      return;
    }

    if (Array.isArray(atts) && atts.length === 1) {
      pv.media.innerHTML = pvSlideInner(atts[0]);
      pvBindMediaOrientation();
      return;
    }

    var t = title;
    var text = (desc || body).trim();
    if (t && !text) {
      pv.media.innerHTML =
        '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;padding:26px;">' +
          '<div style="max-width:640px;color:#fff;text-align:center;">' +
            '<div style="font-weight:800;font-size:24px;line-height:1.25;word-break:break-word;">' + pvEsc(t) + '</div>' +
          '</div></div>';
      return;
    }

    var cut = pvTruncateText(text, 3);
    pv.media.innerHTML =
      '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;padding:26px;">' +
        '<div style="max-width:640px;color:#fff;text-align:left;">' +
          (t ? '<div style="font-weight:800;font-size:22px;line-height:1.2;">' + pvEsc(t) + '</div>' : '') +
          (text ? '<div class="pv-media-text" data-expanded="0" style="margin-top:' + (t ? '10px' : '0') + ';">' +
            (cut.truncated
              ? '<span class="pv-media-short">' + pvFormatRichText(cut.short) + '<span class="pv-rich-ellipsis">&hellip;</span></span>' +
                '<span class="pv-media-full" style="display:none;">' + pvFormatRichText(cut.full) + '</span>' +
                '<a href="#" class="pv-readmore">Read more</a>'
              : '<span>' + pvFormatRichText(cut.full) + '</span>') +
          '</div>' : '') +
        '</div></div>';
  }

  function pvMediaDots(count){
    if (count <= 1) return '';
    var dots = '';
    for (var i = 0; i < count; i++) {
      dots += '<button type="button" class="mf-media-dot' + (i === 0 ? ' is-active' : '') + '" data-index="' + i + '" aria-label="Go to media ' + (i + 1) + '"></button>';
    }
    return '<div class="mf-media-dots" role="tablist">' + dots + '</div>';
  }

  function pvRenderCaption(post, atts, slideIndex){
    var list = Array.isArray(atts) ? atts : (Array.isArray(pvCurrentAttachments) ? pvCurrentAttachments : []);
    var idx = Math.max(0, Number(slideIndex || 0));
    var anySlideText = list.some(function(a){
      return String((a && (a.slide_title || a.slide_body)) || '').trim() !== '';
    });
    var superTitleRaw = String((post && post.title) || '').trim();
    var introRaw = String((post && (post.body || post.description)) || '').trim();
    var taggedForCap = Array.isArray(post && post.tagged_people) ? post.tagged_people : [];
    var superTitle = (window.MSBPostCardMenu && typeof window.MSBPostCardMenu.displayTextWithoutTagHandles === 'function')
      ? window.MSBPostCardMenu.displayTextWithoutTagHandles(superTitleRaw, taggedForCap)
      : superTitleRaw;
    var intro = (window.MSBPostCardMenu && typeof window.MSBPostCardMenu.displayTextWithoutTagHandles === 'function')
      ? window.MSBPostCardMenu.displayTextWithoutTagHandles(introRaw, taggedForCap)
      : introRaw;
    var slideTitle = '';
    var slideDesc = '';
    if (anySlideText) {
      var att = list[idx] || {};
      slideTitle = String(att.slide_title || '').trim();
      slideDesc = String(att.slide_body || '').trim();
    }
    if (!superTitle && !intro && !slideTitle && !slideDesc) {
      pv.caption.style.display = 'none';
      pv.caption.innerHTML = '';
      return;
    }

    pv.caption.style.display = '';
    var titleHtml = superTitle ? '<div class="pv-cap-title">' + pvEsc(superTitle) + '</div>' : '';
    var introHtml = '';
    if (intro) {
      var t = pvTruncateText(intro, 3);
      introHtml = t.truncated
        ? '<div class="pv-cap-desc pv-cap-intro"><span class="pv-cap-short">' + pvFormatRichText(t.short) + '<span class="pv-rich-ellipsis">&hellip;</span></span><span class="pv-cap-full" style="display:none;">' + pvFormatRichText(t.full) + '</span><a href="#" class="pv-readmore">Read more</a></div>'
        : '<div class="pv-cap-desc pv-cap-intro">' + pvFormatRichText(t.full) + '</div>';
    }
    var subHtml = (anySlideText && slideTitle) ? '<div class="pv-cap-subtitle">' + pvEsc(slideTitle) + '</div>' : '';
    var sumHtml = '';
    if (anySlideText && slideDesc) {
      var lines = slideDesc.split('\n').map(function(line){ return String(line || '').trim(); }).filter(Boolean);
      if (lines.length === 1) {
        sumHtml = '<div class="pv-cap-summary"><p class="post-slide-summary-p">' + pvEsc(lines[0]) + '</p></div>';
      } else if (lines.length > 1) {
        sumHtml = '<div class="pv-cap-summary"><ul class="post-slide-summary-list">' +
          lines.map(function(line){ return '<li>' + pvEsc(line) + '</li>'; }).join('') + '</ul></div>';
      }
    }
    pv.caption.innerHTML = '<div class="pv-cap" data-expanded="0">' + titleHtml + introHtml + subHtml + sumHtml + '</div>';
  }

  function pvSetMediaCarouselIndex(carousel, nextIndex){
    if (!carousel) return;
    var slides = carousel.querySelector('.mf-media-slides, .media-slides');
    var dots = carousel.querySelectorAll('.mf-media-dot, .media-dot');
    var slideCount = carousel.querySelectorAll('.mf-media-slide, .media-slide').length;
    if (slideCount < 1) return;
    var idx = Number(nextIndex || 0);
    if (!isFinite(idx)) idx = 0;
    if (idx < 0) idx = 0;
    if (idx > slideCount - 1) idx = slideCount - 1;
    carousel.setAttribute('data-index', String(idx));
    if (slides) slides.style.transform = 'translateX(' + String(idx * -100) + '%)';
    dots.forEach(function(dot){
      var on = Number(dot.getAttribute('data-index')) === idx;
      dot.classList.toggle('is-active', on);
    });
    var prevBtn = carousel.querySelector('.js-pv-media-prev, .mf-media-nav.prev');
    var nextBtn = carousel.querySelector('.js-pv-media-next, .mf-media-nav.next');
    if (prevBtn) prevBtn.style.display = idx > 0 ? '' : 'none';
    if (nextBtn) nextBtn.style.display = idx < slideCount - 1 ? '' : 'none';
    pvRenderCaption(pvCurrentPost || {}, pvCurrentAttachments, idx);
    pvSyncMediaOrientation();
  }

  function pvReplyToggleLabel(count, isOpen){
    return isOpen ? 'Close replies' : ('Open ' + count + ' ' + (count === 1 ? 'reply' : 'replies'));
  }

  function pvRenderComments(post, comments){
    var items = Array.isArray(comments) ? comments : [];
    pvCommentsCache = items;
    if (!items.length) {
      pv.comments.innerHTML = '<div class="t" style="color:rgba(15,23,42,.55);font-size:13px;padding:14px 4px;">No comments yet.</div>';
      return;
    }
    var byId = {};
    items.forEach(function(c){
      byId[Number(c && c.id || 0)] = Object.assign({}, c, { _replies: [] });
    });
    var roots = [];
    Object.keys(byId).forEach(function(key){
      var c = byId[key];
      var parentId = Number(c.parent_id || 0);
      if (parentId > 0 && byId[parentId]) byId[parentId]._replies.push(c);
      else roots.push(c);
    });

    function commentHtml(c, depth){
      var cid = Number(c.id || 0);
      var nm = String(c.display_name || c.username || 'User');
      var txt = String(c.comment_text || '');
      var t = pvTimeAgo(c.created_at);
      var ava = pvAvatarUrlFor(c || {}, 72);
      var kids = Array.isArray(c._replies) ? c._replies : [];
      var replyCount = kids.length;
      var repliesOpen = !pvCollapsedReplyIds.has(cid);
      var childrenHtml = kids.map(function(child){ return commentHtml(child, depth + 1); }).join('');
      return '<div class="pv-node' + (depth > 0 ? ' is-reply' : '') + (replyCount > 0 ? ' has-children' : '') + (replyCount > 0 && !repliesOpen ? ' is-collapsed' : '') + '">' +
        '<div class="pv-com" data-cid="' + cid + '">' +
          '<div class="a"><img src="' + pvEsc(ava) + '" alt="' + pvEsc(nm) + '" /></div>' +
          '<div class="b"><div class="nm">' + pvEsc(nm) + '</div>' +
            '<div class="tx">' + ((window.MSBMentionAC && window.MSBMentionAC.linkify) ? window.MSBMentionAC.linkify(txt) : pvEsc(txt)) + '</div>' +
            '<div class="m"><span>' + pvEsc(t) + '</span>' +
              '<button type="button" class="link replies-toggle pv-reply" data-cid="' + cid + '" data-name="' + pvEsc(nm) + '">Reply</button>' +
              (replyCount > 0 ? '<button type="button" class="link replies-toggle pv-toggle-replies" data-toggle-replies="' + cid + '">' + pvEsc(pvReplyToggleLabel(replyCount, repliesOpen)) + '</button>' : '') +
            '</div></div></div>' +
        (replyCount > 0 && repliesOpen ? '<div class="pv-children">' + childrenHtml + '</div>' : '') +
      '</div>';
    }

    pv.comments.innerHTML = roots.map(function(c){ return commentHtml(c, 0); }).join('');
  }

  function pvReactionTotal(counts, post){
    counts = counts || {};
    post = post || {};
    if (counts.reaction_count != null && counts.reaction_count !== '') {
      return Number(counts.reaction_count || 0);
    }
    if (post.reaction_count != null && post.reaction_count !== '') {
      return Number(post.reaction_count || 0);
    }
    var hasLove = counts.love_count != null || post.love_count != null;
    var hasLike = counts.like_count != null || post.like_count != null;
    if (!hasLove && !hasLike) return null;
    var love = Number(counts.love_count != null ? counts.love_count : (post.love_count || 0));
    var like = Number(counts.like_count != null ? counts.like_count : (post.like_count || 0));
    return love + like;
  }

  function pvSyncLoveCountEl(){
    // applyReactionButton rebuilds icon nodes; keep a live handle on the count span.
    pv.loveN = document.getElementById('pvLoveN') || (pv.love ? pv.love.querySelector('.pv-n, [data-count], .mf-num') : null);
    return pv.loveN;
  }

  function pvSetLoveCount(n){
    var el = pvSyncLoveCountEl();
    if (!el) return;
    el.textContent = String(Math.max(0, Number(n || 0)));
  }

  function pvApplyCounts(data){
    var post = (data && data.post) || {};
    var counts = (data && data.counts) || data || {};
    var dn = String(post.display_name || post.username || '');
    if (dn) {
      var taggedPeople = Array.isArray(post.tagged_people) ? post.tagged_people : [];
      var nameHtml = (window.MSBPostCardMenu && typeof window.MSBPostCardMenu.authorSharingWithHtml === 'function')
        ? window.MSBPostCardMenu.authorSharingWithHtml({
            display_name: dn,
            username: post.username || '',
            id: post.user_id || post.author_id || 0
          }, taggedPeople, { linkAuthor: true })
        : pvEsc(dn);
      pv.name.innerHTML = nameHtml;
      pv.name.classList.toggle('is-sharing-with', taggedPeople.length > 0);
      if (pv.avatar) pv.avatar.src = pvAvatarUrlFor(post || {}, 96);
    }
    if (post.created_at && pv.meta) {
      pv.meta.textContent = 'Posted ' + pvTimeAgo(post.created_at);
      if (window.MSBPostCardMenu && typeof window.MSBPostCardMenu.visibilityBadgeHtml === 'function') {
        pv.meta.insertAdjacentHTML('beforeend', ' ' + window.MSBPostCardMenu.visibilityBadgeHtml(post.visibility || 'public'));
      }
    }
    // Heart button shows TOTAL reactions (love + like/thumbs/faces), same as For You / Discover.
    var totalRx = pvReactionTotal(counts, post);
    if (totalRx != null) pvSetLoveCount(totalRx);
    if (pv.likeN && (counts.like_count != null || post.like_count != null)) {
      pv.likeN.textContent = String(Number(counts.like_count != null ? counts.like_count : (post.like_count || 0)));
    }
    if (pv.comN && counts.comment_count != null) {
      pv.comN.textContent = String(Number(counts.comment_count || 0));
    }
    if (pv.shareN && (counts.share_count != null || post.share_count != null)) {
      pv.shareN.textContent = String(Number(counts.share_count != null ? counts.share_count : (post.share_count || 0)));
    }
    if (pv.saveN && (counts.save_count != null || post.save_count != null)) {
      pv.saveN.textContent = String(Number(counts.save_count != null ? counts.save_count : (post.save_count || 0)));
    }
    if (pv.share && (counts.is_shared != null || counts.my_shared != null || post.my_shared != null)) {
      var sharedOn = Number(counts.is_shared || counts.my_shared || post.my_shared || 0) === 1;
      pv.share.classList.toggle('is-share', sharedOn);
      var shareIcon = pv.share.querySelector('.msb-pact-share');
      if (shareIcon) shareIcon.classList.toggle('is-active', sharedOn);
    }
    if (pv.save && (counts.is_saved != null || counts.my_saved != null || post.my_saved != null)) {
      var savedOn = Number(counts.is_saved || counts.my_saved || post.my_saved || 0) === 1;
      pv.save.classList.toggle('is-save', savedOn);
      var saveIcon = pv.save.querySelector('.msb-pact-bookmark');
      if (saveIcon) saveIcon.classList.toggle('is-active', savedOn);
    }
    if (counts.my_reaction != null || post.my_reaction != null || (data && data.post && Object.keys(post).length)) {
      var my = String(counts.my_reaction != null ? counts.my_reaction : (post.my_reaction != null ? post.my_reaction : pvCurrentReaction));
      pvSetActiveReaction(my);
      if (window.MSBReactions) {
        window.MSBReactions.applyReactionButton(pv.love, my, 'love');
        window.MSBReactions.applyLikeButton(pv.like, my === 'like' ? my : '');
      } else {
        if (pv.love) pv.love.classList.toggle('is-love', my !== '' && my !== 'like');
        if (pv.like) pv.like.classList.toggle('is-like', my === 'like');
      }
      // Re-apply count after icon swap so the number never disappears with the glyph.
      if (totalRx != null) pvSetLoveCount(totalRx);
    }
    if (pv.viewN) pv.viewN.textContent = String(Number(post.views_count || counts.views_count || 0));
  }

  function pvApplyTrack(res){
    if (!res) return;
    var state = res.state || {};
    var shared = Number(state.shared ?? res.my_shared ?? res.is_shared ?? 0) === 1;
    var saved = Number(state.saved ?? res.my_saved ?? res.is_saved ?? 0) === 1;
    if (pv.shareN && res.share_count != null) pv.shareN.textContent = String(Number(res.share_count || 0));
    if (pv.saveN && res.save_count != null) pv.saveN.textContent = String(Number(res.save_count || 0));
    if (pv.share) {
      pv.share.classList.toggle('is-share', shared);
      var shareIcon = pv.share.querySelector('.msb-pact-share');
      if (shareIcon) shareIcon.classList.toggle('is-active', shared);
      if (pvPostId) pv.share.setAttribute('data-post-id', String(pvPostId));
    }
    if (pv.save) {
      pv.save.classList.toggle('is-save', saved);
      var saveIcon = pv.save.querySelector('.msb-pact-bookmark');
      if (saveIcon) saveIcon.classList.toggle('is-active', saved);
      if (pvPostId) pv.save.setAttribute('data-post-id', String(pvPostId));
    }
  }

  function pvBindLovePicker(){
    if (!pv.love) return;
    if (pv.love.getAttribute('data-msb-rx-bound') === '1') return;
    if (!window.MSBReactions || typeof window.MSBReactions.bindLikePicker !== 'function') return;
    pv.love.classList.add('js-react-love');
    if (pvPostId) pv.love.setAttribute('data-post-id', String(pvPostId));
    var onSelect = async function(btn, reaction){
      if (!pvPostId || !reaction) return;
      var next = String(reaction || 'none');
      if (next !== 'none' && next === pvCurrentReaction) return;
      var prev = pvCurrentReaction;
      var prevTotal = Number((pvSyncLoveCountEl() && pv.loveN.textContent) || 0);
      var nextMy = next === 'none' ? '' : next;
      var optimisticTotal = prevTotal;
      if (!prev && nextMy) optimisticTotal = prevTotal + 1;
      else if (prev && !nextMy) optimisticTotal = Math.max(0, prevTotal - 1);
      try {
        if (pv.ov) pv.ov.setAttribute('data-engage-at', String(Date.now()));
        pvSetActiveReaction(nextMy);
        if (window.MSBReactions) window.MSBReactions.applyReactionButton(pv.love, nextMy, 'love');
        pvSetLoveCount(optimisticTotal);
        var data = await pvJson(PV_API + '?ajax=react', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: 'post_id=' + encodeURIComponent(pvPostId) + '&reaction=' + encodeURIComponent(next),
          credentials: 'same-origin'
        });
        var counts = Object.assign({}, data.counts || {});
        if (counts.my_reaction == null || counts.my_reaction === '') {
          if (nextMy) counts.my_reaction = nextMy;
        }
        if (counts.reaction_count == null && counts.love_count != null && counts.like_count != null) {
          counts.reaction_count = Number(counts.love_count || 0) + Number(counts.like_count || 0);
        }
        if (counts.reaction_count == null) counts.reaction_count = optimisticTotal;
        pvApplyCounts({ post: {}, counts: counts });
        pvPublishReact(pvPostId, { counts: counts });
      } catch (e) {
        pvSetActiveReaction(prev);
        if (window.MSBReactions) window.MSBReactions.applyReactionButton(pv.love, prev, 'love');
        pvSetLoveCount(prevTotal);
      }
    };
    window.MSBReactions.bindLikePicker('#pvLove', onSelect);
    window.MSBReactions.bindLikePicker('#pvOverlay .js-react-love', onSelect);
    pv.love.setAttribute('data-msb-rx-bound', '1');
    if (!pv.love.getAttribute('data-msb-rx-click')) {
      pv.love.setAttribute('data-msb-rx-click', '1');
      pv.love.addEventListener('click', function(e){
        if (!window.MSBReactions) return;
        // Same as For You / Discover: click opens the reaction tray.
        if (typeof window.MSBReactions.openPickerFor === 'function') {
          e.preventDefault();
          e.stopPropagation();
          if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
          window.MSBReactions.openPickerFor(pv.love);
        }
      });
    }
  }

  async function pvLoad(postId){
    postId = Number(postId || 0);
    if (!postId) return;
    var seq = ++pvLoadSeq;
    var alreadyOpen = pv.ov.classList.contains('show');
    var hadContent = !!(pv.media && pv.media.childElementCount && !pv.media.querySelector('.pv-loading-only'));
    pv.ov.classList.toggle('pv-is-switching', alreadyOpen && hadContent);
    if (!pv.media.childElementCount) {
      pv.media.innerHTML = '<div class="pv-loading-only" style="display:flex;align-items:center;justify-content:center;min-width:min(360px,42vw);height:100%;background:#0b1220;color:rgba(255,255,255,.7);">Loading…</div>';
    }
    try {
      var view = await pvJson(PV_API + '?ajax=view&id=' + encodeURIComponent(postId) + '&count_view=1', { credentials: 'same-origin' });
      if (seq !== pvLoadSeq) return;
      pvCurrentPost = view.post || null;
      pvCurrentAttachments = Array.isArray(view.attachments) ? view.attachments.slice() : [];
      pvRenderMedia(view.post, view.attachments);
      pvRenderCaption(view.post, view.attachments, 0);
      pvRenderComments(view.post, view.comments);
      pvApplyCounts(view);
      if (pv.comN) {
        var commentCount = (view.counts && view.counts.comment_count != null)
          ? Number(view.counts.comment_count)
          : (Array.isArray(view.comments) ? view.comments.length : 0);
        pv.comN.textContent = String(commentCount);
      }
      if (view.counts) {
        pvPublishReact(postId, { counts: view.counts });
      }
      var tc = await pvJson(PV_API + '?ajax=track_counts&post_id=' + encodeURIComponent(postId), { credentials: 'same-origin' });
      if (seq !== pvLoadSeq) return;
      pvApplyTrack(tc);
      pvPublishTrack(postId, tc);
      if (pv.love) pv.love.setAttribute('data-post-id', String(postId));
      if (pv.share) pv.share.setAttribute('data-post-id', String(postId));
      if (pv.save) pv.save.setAttribute('data-post-id', String(postId));
      pvBindLovePicker();
    } catch (e) {
      if (seq !== pvLoadSeq) return;
      pv.media.innerHTML = '<div style="color:#fff;opacity:.85;padding:24px;">Failed to load post.</div>';
      pv.caption.style.display = 'none';
      pv.caption.innerHTML = '';
      pv.comments.innerHTML = '<div style="color:#b91c1c;font-size:13px;padding:14px 4px;">' + pvEsc(e && e.message ? e.message : 'Failed') + '</div>';
    } finally {
      if (seq === pvLoadSeq) pv.ov.classList.remove('pv-is-switching');
    }
  }

  function pvClose(){
    pv.ov.classList.remove('show');
    pv.ov.setAttribute('hidden', '');
    pv.ov.setAttribute('aria-hidden', 'true');
    pv.ov.style.display = 'none';
    pv.ov.setAttribute('aria-hidden', 'true');
    pvUnlockBodyScroll();
    pvSetVh();
    pv.media.innerHTML = '';
    pv.caption.innerHTML = '';
    pv.caption.style.display = 'none';
    pv.comments.innerHTML = '';
    pvCommentsCache = [];
    pvCollapsedReplyIds.clear();
    pvSetActivePostId(0);
    pvSetActiveReaction('');
    pvSetReply(0, '');
    pv.ov.classList.remove('pv-is-portrait', 'pv-is-landscape');
  }

  window.pvClose = pvClose;

  window.pvOpenById = function(postId, opts){
    postId = Number(postId || 0);
    if (!postId) return false;
    // Shared modal has no gallery prev/next; opts.hideNav is accepted for API parity.
    opts = (opts && typeof opts === 'object') ? opts : {};
    try { window.__pvHidePostNav = !!(opts.hideNav || opts.standalone || opts.fromMention || opts.fromTag); } catch (e) {}
    pvSetActivePostId(postId);
    pvSetReply(0, '');
    pvCollapsedReplyIds.clear();
    pvCommentsCache = [];
    pvSetVh();
    pv.ov.removeAttribute('hidden');
    pv.ov.style.display = '';
    pv.ov.setAttribute('aria-hidden', 'false');
    pv.ov.classList.add('show');
    pv.ov.setAttribute('aria-hidden', 'false');
    pvLockBodyScroll();
    if (pv.love) pv.love.setAttribute('data-post-id', String(postId));
    if (pv.share) pv.share.setAttribute('data-post-id', String(postId));
    if (pv.save) pv.save.setAttribute('data-post-id', String(postId));
    pvBindLovePicker();
    pvLoad(postId);
    return true;
  };

  async function pvPostComment(){
    if (!pvPostId || !pv.text) return;
    var text = String(pv.text.value || '').trim();
    if (!text) return;
    if (pv.postBtn) pv.postBtn.disabled = true;
    try {
      var body = 'post_id=' + encodeURIComponent(pvPostId) + '&comment_text=' + encodeURIComponent(text);
      if (pvReplyTo > 0) body += '&parent_id=' + encodeURIComponent(pvReplyTo);
      await pvJson(PV_API + '?ajax=comment', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body,
        credentials: 'same-origin'
      });
      pv.text.value = '';
      pvSetReply(0, '');
      await pvLoad(pvPostId);
      if (pv.comN) pvPublishCommentCount(pvPostId, pv.comN.textContent);
      if (pv.comments) pv.comments.scrollTop = pv.comments.scrollHeight;
    } catch (e) {
    } finally {
      if (pv.postBtn) pv.postBtn.disabled = false;
    }
  }

  if (pv.close) pv.close.addEventListener('click', pvClose);
  pv.ov.addEventListener('mousedown', function(e){ if (e.target === pv.ov) pvClose(); });
  document.addEventListener('keydown', function(e){
    if (!pv.ov.classList.contains('show')) return;
    if (e.key === 'Escape') { e.preventDefault(); pvClose(); }
  });

  document.addEventListener('click', function(e){
    if (!e.target.closest('#pvMedia')) return;
    var btn = e.target.closest('.js-pv-media-prev, .js-pv-media-next, .mf-media-dot, .media-dot');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    var carousel = btn.closest('.mf-media-carousel, .media-carousel');
    if (!carousel) return;
    var current = Number(carousel.getAttribute('data-index') || 0);
    var next = current;
    if (btn.classList.contains('js-pv-media-prev') || btn.classList.contains('prev')) next = current - 1;
    else if (btn.classList.contains('js-pv-media-next') || btn.classList.contains('next')) next = current + 1;
    else next = Number(btn.getAttribute('data-index') || 0);
    pvSetMediaCarouselIndex(carousel, next);
  });

  document.addEventListener('click', function(e){
    var rm = e.target.closest('.pv-readmore');
    if (!rm || !rm.closest('#pvOverlay')) return;
    e.preventDefault();
    var cap = rm.closest('.pv-cap');
    if (cap && cap.querySelector('.pv-cap-short') && cap.querySelector('.pv-cap-full')) {
      var expanded = cap.getAttribute('data-expanded') === '1';
      cap.setAttribute('data-expanded', expanded ? '0' : '1');
      cap.querySelector('.pv-cap-short').style.display = expanded ? '' : 'none';
      cap.querySelector('.pv-cap-full').style.display = expanded ? 'none' : '';
      rm.textContent = expanded ? 'Read more' : 'Show less';
      return;
    }
    var mt = rm.closest('.pv-media-text');
    if (mt && mt.querySelector('.pv-media-short') && mt.querySelector('.pv-media-full')) {
      var expandedMt = mt.getAttribute('data-expanded') === '1';
      mt.setAttribute('data-expanded', expandedMt ? '0' : '1');
      mt.querySelector('.pv-media-short').style.display = expandedMt ? '' : 'none';
      mt.querySelector('.pv-media-full').style.display = expandedMt ? 'none' : '';
      rm.textContent = expandedMt ? 'Read more' : 'Show less';
    }
  });

  if (pv.comments) {
    pv.comments.addEventListener('click', function(e){
      var toggleBtn = e.target.closest('.pv-toggle-replies');
      if (toggleBtn) {
        var cid = Number(toggleBtn.getAttribute('data-toggle-replies') || 0);
        if (!cid) return;
        if (pvCollapsedReplyIds.has(cid)) pvCollapsedReplyIds.delete(cid);
        else pvCollapsedReplyIds.add(cid);
        pvRenderComments({}, pvCommentsCache);
        return;
      }
      var r = e.target.closest('.pv-reply');
      if (!r) return;
      pvSetReply(Number(r.getAttribute('data-cid') || 0), String(r.getAttribute('data-name') || ''));
      if (pv.text) pv.text.focus();
    });
  }

  if (pv.replyCancel) pv.replyCancel.addEventListener('click', function(){ pvSetReply(0, ''); });
  if (pv.focusComment) pv.focusComment.addEventListener('click', function(){ if (pv.text) pv.text.focus(); });
  if (pv.postBtn) pv.postBtn.addEventListener('click', pvPostComment);
  if (pv.text) pv.text.addEventListener('keydown', function(e){
    if (e.key === 'Enter') { e.preventDefault(); pvPostComment(); }
  });

  pvBindLovePicker();
  // Late MSBReactions (or skipped first pass): keep trying briefly.
  (function pvRetryLoveBind(){
    var tries = 0;
    var timer = window.setInterval(function(){
      tries += 1;
      pvBindLovePicker();
      if ((pv.love && pv.love.getAttribute('data-msb-rx-bound') === '1') || tries >= 20) {
        window.clearInterval(timer);
      }
    }, 150);
  })();

  if (pv.share) {
    pv.share.addEventListener('click', async function(){
      if (!pvPostId) return;
      if (window.MSBPostCardMenu && typeof window.MSBPostCardMenu.openShare === 'function') {
        window.MSBPostCardMenu.openShare(pvPostId);
        return;
      }
      try {
        var res = await pvJson(PV_API + '?ajax=share', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: 'post_id=' + encodeURIComponent(pvPostId),
          credentials: 'same-origin'
        });
        pvApplyTrack(res);
        pvPublishTrack(pvPostId, res);
      } catch (e) {}
    });
  }

  if (pv.save) {
    pv.save.addEventListener('click', async function(){
      if (!pvPostId) return;
      try {
        var res = await pvJson(PV_API + '?ajax=save', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: 'post_id=' + encodeURIComponent(pvPostId),
          credentials: 'same-origin'
        });
        pvApplyTrack(res);
        pvPublishTrack(pvPostId, res);
      } catch (e) {}
    });
  }

  if (window.MSBPostEngagement && typeof window.MSBPostEngagement.registerAdapter === 'function') {
    window.MSBPostEngagement.registerAdapter(function(postId, patch){
      if (Number(postId) !== Number(pvPostId) || !patch) return;
      if (patch.my_reaction != null) pvSetActiveReaction(patch.my_reaction);
      var counts = {
        love_count: patch.love_count,
        like_count: patch.like_count,
        reaction_count: patch.reaction_count,
        comment_count: patch.comment_count,
        share_count: patch.share_count,
        save_count: patch.save_count,
        my_reaction: patch.my_reaction,
        is_shared: patch.is_shared,
        is_saved: patch.is_saved
      };
      if (counts.reaction_count == null && counts.love_count != null && counts.like_count != null) {
        counts.reaction_count = Number(counts.love_count || 0) + Number(counts.like_count || 0);
      }
      pvApplyCounts({ post: {}, counts: counts });
      if (patch.share_count != null || patch.save_count != null || patch.is_shared != null || patch.is_saved != null) {
        pvApplyTrack({
          share_count: patch.share_count != null ? patch.share_count : (pv.shareN ? pv.shareN.textContent : 0),
          save_count: patch.save_count != null ? patch.save_count : (pv.saveN ? pv.saveN.textContent : 0),
          state: {
            shared: patch.is_shared != null ? patch.is_shared : ((pv.share && pv.share.classList.contains('is-share')) ? 1 : 0),
            saved: patch.is_saved != null ? patch.is_saved : ((pv.save && pv.save.classList.contains('is-save')) ? 1 : 0)
          }
        });
      }
    });
  }

  (function pvComposerChrome(){
    function insertAtCursor(input, chunk){
      if (!input) return;
      var start = input.selectionStart != null ? input.selectionStart : input.value.length;
      var end = input.selectionEnd != null ? input.selectionEnd : input.value.length;
      input.value = input.value.slice(0, start) + chunk + input.value.slice(end);
      try { input.setSelectionRange(start + chunk.length, start + chunk.length); } catch (e) {}
      input.focus();
    }
    var atBtn = document.getElementById('pvAtBtn');
    var emojiBtn = document.getElementById('pvEmojiBtn');
    if (atBtn && pv.text) atBtn.addEventListener('click', function(){ insertAtCursor(pv.text, '@'); });
    if (emojiBtn && pv.text) emojiBtn.addEventListener('click', function(){ insertAtCursor(pv.text, '😊'); });
  })();
})();
</script>
