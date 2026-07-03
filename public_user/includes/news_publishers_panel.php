<?php
declare(strict_types=1);

/** X news publisher follow strip — include on public.php / news.php. Set $newsPublishersPanelCompact = true for compact mode. */
require_once __DIR__ . '/news_publisher_follows.php';

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$newsPublishersPanelCompact = !empty($newsPublishersPanelCompact);
$meId = (int)($_SESSION['user_id'] ?? 0);
$publishers = news_publisher_follows_with_meta($meId);
?>
<style>
  .news-pub-panel{margin:0 0 18px;padding:14px 16px;background:#fff;border:1px solid var(--public-border,#e5e7eb);border-radius:16px}
  .news-pub-panel-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:12px}
  .news-pub-panel-head h2{margin:0;font-size:15px;font-weight:900}
  .news-pub-panel-head p{margin:4px 0 0;font-size:12px;color:var(--public-muted,#6b7280);line-height:1.45;max-width:420px}
  .news-pub-panel-link{font-size:12px;font-weight:800;color:var(--public-accent,#2563eb);white-space:nowrap}
  .news-pub-scroll{display:flex;gap:10px;overflow-x:auto;padding-bottom:4px;scrollbar-width:none}
  .news-pub-scroll::-webkit-scrollbar{display:none}
  .news-pub-card{flex:0 0 auto;width:108px;text-align:center}
  .news-pub-avatar{width:56px;height:56px;border-radius:50%;margin:0 auto 8px;overflow:hidden;background:linear-gradient(135deg,#0ea5e9,#2563eb);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:16px;border:2px solid #fff;box-shadow:0 0 0 1px rgba(15,23,42,.08)}
  .news-pub-avatar img{width:100%;height:100%;object-fit:cover;display:block}
  .news-pub-name{font-size:11px;font-weight:800;line-height:1.25;margin-bottom:8px;min-height:28px;display:flex;align-items:center;justify-content:center}
  .news-pub-follow{width:100%;height:30px;border-radius:999px;border:1px solid var(--public-border,#e5e7eb);background:#fff;font-size:11px;font-weight:800;cursor:pointer;padding:0 8px}
  .news-pub-follow.is-following{background:#111827;border-color:#111827;color:#fff}
  .news-pub-note{margin-top:10px;font-size:11px;color:var(--public-muted,#6b7280);line-height:1.4}
  <?php if ($newsPublishersPanelCompact): ?>
  .news-pub-panel{margin:0 0 14px;padding:12px 14px}
  .news-pub-card{width:96px}
  .news-pub-avatar{width:48px;height:48px}
  <?php endif; ?>
</style>
<section class="news-pub-panel" id="newsPubPanel" aria-label="Follow news on X">
  <div class="news-pub-panel-head">
    <div>
      <h2><i class="fa fa-twitter"></i> Follow news on X</h2>
      <p>Follow CNN, Fox News, ABC, BBC, FOX Sports, and more. Read their daily updates here — no direct messages to publishers.</p>
    </div>
    <a class="news-pub-panel-link" href="news.php?category=following">Your feed →</a>
  </div>
  <div class="news-pub-scroll" id="newsPubScroll">
    <?php foreach ($publishers as $pub): ?>
      <article class="news-pub-card" data-publisher-key="<?= h((string)$pub['key']) ?>">
        <div class="news-pub-avatar">
          <?php if ((string)($pub['avatar'] ?? '') !== ''): ?>
            <img src="<?= h((string)$pub['avatar']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
          <?php else: ?>
            <?= h(strtoupper(substr((string)$pub['label'], 0, 1))) ?>
          <?php endif; ?>
        </div>
        <div class="news-pub-name"><?= h((string)$pub['label']) ?></div>
        <button type="button" class="news-pub-follow<?= !empty($pub['following']) ? ' is-following' : '' ?>" data-publisher-key="<?= h((string)$pub['key']) ?>">
          <?= !empty($pub['following']) ? 'Following' : 'Follow' ?>
        </button>
      </article>
    <?php endforeach; ?>
  </div>
  <div class="news-pub-note">Tip: after you follow sources, open <strong>News → Following</strong> to see only their latest posts. Love, comment, save, and share stay on Talentra — publishers never receive your messages.</div>
</section>
<script>
(function(){
  var panel = document.getElementById('newsPubPanel');
  if (!panel) return;
  panel.addEventListener('click', function(e){
    var btn = e.target.closest('.news-pub-follow');
    if (!btn) return;
    e.preventDefault();
    var key = btn.getAttribute('data-publisher-key') || '';
    if (!key) return;
    var fd = new FormData();
    fd.append('action', 'toggle');
    fd.append('publisher_key', key);
    fetch('news_follow_api.php', { method:'POST', body: fd, cache:'no-store' })
      .then(function(r){ return r.json(); })
      .then(function(res){
        if (!res || !res.ok) return;
        var following = !!res.following;
        btn.classList.toggle('is-following', following);
        btn.textContent = following ? 'Following' : 'Follow';
      })
      .catch(function(){});
  });
})();
</script>
