/*! MyStoryBook post videos: clean, muted, inline, continuously looping playback. */
(function () {
  'use strict';

  if (window.__msbPostVideoLoopReady) return;
  window.__msbPostVideoLoopReady = true;

  var selector = [
    '.mf-card video',
    '.public-post-card video',
    '.reel-slide video',
    '#profilePostsFeed video',
    '#pvMedia video'
  ].join(',');

  var observer = null;

  function isPostVideo(video) {
    if (!video || !video.matches || !video.matches(selector)) return false;
    if (video.closest('.global-live-modal, .global-live-door, [data-live-room]')) return false;
    return true;
  }

  function play(video) {
    if (!isPostVideo(video)) return;
    try {
      var promise = video.play();
      if (promise && typeof promise.catch === 'function') promise.catch(function () {});
    } catch (e) {}
  }

  function configure(video) {
    if (!isPostVideo(video)) return;

    video.controls = false;
    video.removeAttribute('controls');
    video.autoplay = true;
    video.setAttribute('autoplay', '');
    video.loop = true;
    video.setAttribute('loop', '');
    video.muted = true;
    video.defaultMuted = true;
    video.setAttribute('muted', '');
    video.playsInline = true;
    video.setAttribute('playsinline', '');
    video.setAttribute('webkit-playsinline', '');
    video.disablePictureInPicture = true;
    video.setAttribute('disablepictureinpicture', '');
    video.setAttribute('controlslist', 'nodownload noplaybackrate nofullscreen');
    video.classList.add('msb-clean-loop-video');

    if (!video.preload || video.preload === 'none') video.preload = 'metadata';
    if (video.dataset.msbLoopBound === '1') return;
    video.dataset.msbLoopBound = '1';

    video.addEventListener('ended', function () {
      try { video.currentTime = 0; } catch (e) {}
      play(video);
    });
    if (observer) observer.observe(video);
    else play(video);
  }

  function scan(root) {
    root = root && root.querySelectorAll ? root : document;
    if (root.matches && root.matches(selector)) configure(root);
    Array.prototype.forEach.call(root.querySelectorAll(selector), configure);
  }

  function init() {
    if ('IntersectionObserver' in window) {
      observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          var video = entry.target;
          var visible = !!entry.isIntersecting && Number(entry.intersectionRatio || 0) >= 0.35;
          video.dataset.msbInView = visible ? '1' : '0';
          if (visible) play(video);
          else {
            try { video.pause(); } catch (e) {}
          }
        });
      }, { threshold: [0, 0.35, 0.7] });
    }

    scan(document);

    try {
      new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
          Array.prototype.forEach.call(mutation.addedNodes || [], function (node) {
            if (node && node.nodeType === 1) scan(node);
          });
        });
      }).observe(document.body, { childList: true, subtree: true });
    } catch (e) {}

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) return;
      Array.prototype.forEach.call(document.querySelectorAll(selector), function (video) {
        if (video.dataset.msbInView === '1') play(video);
      });
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
