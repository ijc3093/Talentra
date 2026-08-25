<?php
declare(strict_types=1);

/** Publisher discover strip for public.php — Follow only, no Friend / no DM. */
require_once __DIR__ . '/publisher_accounts.php';

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('user_avatar_url')) {
    function user_avatar_url(array $user, int $size = 96): string
    {
        $img = trim((string)($user['image'] ?? ''));
        if ($img !== '' && $img !== 'default.jpg') {
            return $img;
        }
        return 'avatar.php?id=' . (int)($user['id'] ?? 0) . '&s=' . $size;
    }
}

$pubDbh = $dbh ?? null;
if (!$pubDbh instanceof PDO) {
    return;
}

publisher_ensure_schema($pubDbh);
$panelMeId = (int)($meId ?? 0);
if ($panelMeId > 0 && publisher_workspace_viewer($pubDbh, $panelMeId)) {
    return;
}
$panelQuery = trim((string)($publisherSearchQuery ?? ''));
$allPublishers = publisher_list($pubDbh, '', 24);
if ($panelQuery !== '') {
    $qLower = mb_strtolower($panelQuery);
    $allPublishers = array_values(array_filter($allPublishers, static function (array $row) use ($qLower): bool {
        $hay = mb_strtolower(
            ($row['name'] ?? '') . ' ' . ($row['username'] ?? '') . ' ' . ($row['publisher_tagline'] ?? '') . ' ' . ($row['designation'] ?? '')
        );
        return strpos($hay, $qLower) !== false;
    }));
}
$publishers = publisher_attach_follow_state($pubDbh, $allPublishers, (int)($meId ?? 0));
if (!$publishers && $panelQuery === '') {
    return;
}
?>
<style>
  .pub-discover{margin:0 0 18px;padding:14px 16px;background:#fff;border:1px solid var(--public-border,#e5e7eb);border-radius:16px}
  .pub-discover-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:10px}
  .pub-discover-head h2{margin:0;font-size:15px;font-weight:900}
  .pub-discover-head p{margin:4px 0 0;font-size:12px;color:var(--public-muted,#6b7280);line-height:1.45;max-width:460px}
  .pub-discover-note{margin-top:10px;font-size:11px;color:var(--public-muted,#6b7280);line-height:1.4}
  .pub-discover-scroll{display:flex;gap:10px;overflow-x:auto;padding-bottom:4px;scrollbar-width:none}
  .pub-discover-scroll::-webkit-scrollbar{display:none}
  .pub-discover-card{flex:0 0 auto;width:108px;text-align:center}
  .pub-discover-avatar{width:56px;height:56px;border-radius:50%;margin:0 auto 8px;overflow:hidden;background:#eef2ff;display:flex;align-items:center;justify-content:center}
  .pub-discover-avatar img{width:100%;height:100%;object-fit:cover}
  .pub-discover-name{font-size:11px;font-weight:800;line-height:1.25;margin-bottom:6px;min-height:28px}
  .pub-discover-cat{font-size:10px;color:var(--public-muted,#6b7280);margin-bottom:8px;text-transform:uppercase;letter-spacing:.03em}
  .pub-follow-btn{width:100%;height:30px;border-radius:999px;border:1px solid var(--public-border,#e5e7eb);background:#fff;font-size:11px;font-weight:800;cursor:pointer}
  .pub-follow-btn.is-following{background:#111827;border-color:#111827;color:#fff}
  .pub-discover-link{font-size:12px;font-weight:800;color:var(--public-accent,#2563eb);white-space:nowrap}
</style>
<section class="pub-discover" aria-label="Follow news publishers">
  <div class="pub-discover-head">
    <div>
      <h2>Follow news &amp; brands</h2>
      <p>Browse publisher posts on <strong>Public</strong>. Tap <strong>Follow</strong> and their updates move to your <strong>Feed</strong>, where you can react and comment.</p>
    </div>
    <a class="pub-discover-link" href="register.php?account_type=publisher">Register as publisher</a>
  </div>
  <div class="pub-discover-scroll">
    <?php foreach ($publishers as $pub): ?>
      <?php
        $pid = (int)($pub['id'] ?? 0);
        $catKey = (string)($pub['publisher_category'] ?? '');
        $catLabel = publisher_categories()[$catKey] ?? $catKey;
      ?>
      <article class="pub-discover-card">
        <a href="profile.php?u=<?= rawurlencode((string)($pub['username'] ?? '')) ?>" class="pub-discover-avatar">
          <img src="<?= h(user_avatar_url($pub, 96)) ?>" alt="" loading="lazy">
        </a>
        <div class="pub-discover-name"><?= h((string)($pub['name'] ?? '')) ?></div>
        <?php if ($catLabel !== ''): ?><div class="pub-discover-cat"><?= h($catLabel) ?></div><?php endif; ?>
        <button type="button" class="pub-follow-btn<?= !empty($pub['is_following']) ? ' is-following' : '' ?>" data-publisher-id="<?= $pid ?>">
          <?= !empty($pub['is_following']) ? 'Following' : 'Follow' ?>
        </button>
      </article>
    <?php endforeach; ?>
  </div>
  <div class="pub-discover-note">Follow is one-way — different from <strong>Friend</strong> (family, partner, close friends). Browse publishers on <a href="news.php" style="font-weight:700;color:#2563eb">news.php</a> and <a href="public.php" style="font-weight:700;color:#2563eb">public.php</a>. After you follow, their posts also appear in your <a href="feed.php" style="font-weight:700;color:#2563eb">feed.php</a>.</div>
</section>
<script>
(function(){
  document.addEventListener('click', function(e){
    var btn = e.target.closest('.pub-follow-btn');
    if (!btn) return;
    e.preventDefault();
    var id = btn.getAttribute('data-publisher-id') || '';
    if (!id) return;
    var fd = new FormData();
    fd.append('target_id', id);
    fetch('publisher_follow_toggle.php', { method:'POST', body: fd, cache:'no-store' })
      .then(function(r){ return r.json(); })
      .then(function(res){
        if (!res || !res.ok) return;
        btn.classList.toggle('is-following', !!res.following);
        btn.textContent = res.following ? 'Following' : 'Follow';
        document.querySelectorAll('.pub-follow-btn[data-publisher-id="'+id+'"], .publisher-follow-btn[data-publisher-id="'+id+'"]').forEach(function(other){
          other.classList.toggle('is-following', !!res.following);
          other.classList.toggle('primary', !res.following);
          other.textContent = res.following ? 'Following' : 'Follow';
        });
      });
  });
})();
</script>
