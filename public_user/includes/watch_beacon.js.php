<?php
/**
 * Minimal TikTok-style watch beacon (attention signals).
 * Include once per page that plays reel/feed video.
 */
if (!empty($GLOBALS['msb_watch_beacon_printed'])) {
    return;
}
$GLOBALS['msb_watch_beacon_printed'] = true;
?>
<script>
(function(w){
  if (w.MSBWatchBeacon) return;
  var API = 'feed_api.php';
  var lastSent = {};
  function send(payload){
    try {
      var pid = Number(payload.post_id || 0);
      var wms = Math.max(0, Math.floor(Number(payload.watch_ms || 0)));
      if (!pid || wms < 250) return;
      var key = pid + ':' + Math.floor(wms / 1000);
      if (lastSent[key]) return;
      lastSent[key] = 1;
      var body = new URLSearchParams();
      body.set('ajax', 'watch');
      body.set('post_id', String(pid));
      body.set('watch_ms', String(wms));
      body.set('duration_ms', String(Math.max(0, Math.floor(Number(payload.duration_ms || 0)))));
      body.set('source', String(payload.source || 'feed'));
      if (payload.completed) body.set('completed', '1');
      if (payload.skipped) body.set('skipped', '1');
      if (navigator.sendBeacon) {
        navigator.sendBeacon(API, body);
        return;
      }
      fetch(API, { method:'POST', body: body, credentials:'same-origin', keepalive:true }).catch(function(){});
    } catch (_e) {}
  }
  function fromVideo(video, postId, source){
    if (!video || !postId) return;
    var dur = Math.floor((Number(video.duration) || 0) * 1000);
    var cur = Math.floor((Number(video.currentTime) || 0) * 1000);
    var completed = dur > 0 && cur >= Math.floor(dur * 0.9);
    var skipped = dur > 0 && cur > 0 && cur < Math.floor(dur * 0.2);
    send({ post_id: postId, watch_ms: cur, duration_ms: dur, source: source || 'feed', completed: completed, skipped: skipped });
  }
  w.MSBWatchBeacon = { send: send, fromVideo: fromVideo };
})(window);
</script>
